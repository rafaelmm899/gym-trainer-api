<?php

namespace App\Services\Cycle;

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Ai\Agents\Cycle\CycleProgressionAgent;
use App\Data\Cycle\CyclePlanData;
use App\Data\Cycle\CyclePlanDayData;
use App\Data\Cycle\CyclePlanExerciseData;
use App\Data\Cycle\ExerciseProgressionData;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Enums\Shared\MuscleGroup;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Plans a cycle. The first cycle wraps {@see CyclePlannerAgent}: builds the
 * planning prompt from the athlete profile plus the routine's goal and hint,
 * checks the structured response is a usable 5-day plan, and maps it to
 * {@see CyclePlanData}.
 *
 * Cycle N+1 never re-plans: the routine keeps its exercises, so the outgoing
 * cycle's days and exercises are cloned and {@see CycleProgressionAgent} is
 * asked only to progress the exercises the athlete actually performed (sets,
 * rep range, load, RPE, rest). Exercises that were not performed are copied
 * verbatim and never reach the AI.
 *
 * Every failure — a provider error or an out-of-bounds response — surfaces as
 * {@see CycleGenerationException}. It runs before any database write, so a
 * failure means nothing is persisted (neither the first cycle nor a rollover).
 */
final class CyclePlannerService
{
    private const DAYS_PER_CYCLE = 5;

    public function planFirstCycle(AthleteProfile $profile, Goal $goal, ?string $hint): CyclePlanData
    {
        [$minExercises, $maxExercises] = $this->exercisesPerDayRange($profile->experience_level);

        $structured = $this->promptAgent(CyclePlannerAgent::make(), $this->buildPrompt($profile, $goal, $hint, $minExercises, $maxExercises));

        return $this->mapPlan($structured, $minExercises, $maxExercises);
    }

    /**
     * @param  Cycle  $outgoing  the routine's active cycle, with `cycleDays.dayExercises.exercise` eager-loaded
     * @param  Collection<int, ExerciseRecommendation>  $recommendations  the routine's `active` recommendations
     * @param  array<int, ExerciseProgressionData>  $progressionSummary  keyed by exercise id, from {@see ProgressionSummaryService}
     */
    public function planNextCycle(
        AthleteProfile $profile,
        Goal $goal,
        ?string $hint,
        Cycle $outgoing,
        Collection $recommendations,
        array $progressionSummary,
    ): CyclePlanData {
        $performed = $outgoing->cycleDays
            ->flatMap(fn (CycleDay $day) => $day->dayExercises->map(fn (DayExercise $slot): array => [$day, $slot]))
            ->filter(fn (array $pair): bool => ($progressionSummary[$pair[1]->exercise_id]->performed ?? false))
            ->mapWithKeys(fn (array $pair): array => [$this->slotKey($pair[0]->order, $pair[1]->order) => $pair[1]]);

        if ($performed->isEmpty()) {
            return $this->cloneOutgoing(
                $outgoing,
                'No exercise was trained last cycle; the prescription is unchanged.',
                [],
            );
        }

        $structured = $this->promptAgent(
            CycleProgressionAgent::make(),
            $this->buildProgressionPrompt($profile, $goal, $hint, $outgoing, $performed, $recommendations, $progressionSummary),
        );

        return $this->cloneOutgoing(
            $outgoing,
            $this->requireString($structured, 'split_rationale'),
            $this->mapProgressions($structured, $performed),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function promptAgent(CyclePlannerAgent|CycleProgressionAgent $agent, string $prompt): array
    {
        try {
            $response = $agent->prompt($prompt);
        } catch (Throwable $e) {
            throw new CycleGenerationException(previous: $e);
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw new CycleGenerationException('The planner did not return a structured plan.');
        }

        return $response->toArray();
    }

    /**
     * Exercises per training day for this experience level, as `[min, max]`,
     * from `config('training.cycle.exercises_per_day.*')` (a `"min-max"` string,
     * overridable per level via `CYCLE_EXERCISES_PER_DAY_*` in `.env`).
     *
     * @return array{int, int}
     */
    private function exercisesPerDayRange(ExperienceLevel $level): array
    {
        $raw = (string) config("training.cycle.exercises_per_day.{$level->value}", '3-8');

        $parts = explode('-', $raw, 2);
        $min = max(1, (int) $parts[0]);
        $max = max($min, (int) ($parts[1] ?? '8'));

        return [$min, $max];
    }

    private function buildPrompt(AthleteProfile $profile, Goal $goal, ?string $hint, int $minExercises, int $maxExercises): string
    {
        $lines = [
            'Build the first training week for this athlete.',
            '',
            ...$this->athleteAndRoutineLines($profile, $goal, $hint),
            '',
            'Return exactly 5 training days. All weights are in kilograms.',
            "Prescribe between {$minExercises} and {$maxExercises} exercises on EVERY day "
                .'(this athlete\'s experience level); use the higher end for longer sessions.',
            'Pick ONE count in that range and use it on all 5 days — a day with fewer '
                ."than {$minExercises} exercises makes the whole plan invalid.",
        ];

        return implode("\n", $lines);
    }

    /**
     * One line per performed slot: its current prescription, what the athlete
     * really did, and the exercise's active recommendation, if any.
     *
     * @param  Collection<string, DayExercise>  $performed  keyed by {@see self::slotKey()}
     * @param  Collection<int, ExerciseRecommendation>  $recommendations
     * @param  array<int, ExerciseProgressionData>  $progressionSummary
     */
    private function buildProgressionPrompt(
        AthleteProfile $profile,
        Goal $goal,
        ?string $hint,
        Cycle $outgoing,
        Collection $performed,
        Collection $recommendations,
        array $progressionSummary,
    ): string {
        $recommendationsByExercise = $recommendations->keyBy('exercise_id');

        $lines = [
            'Review the training week this athlete just finished and progress each exercise listed below. '
                .'The exercises stay exactly the same; you only decide sets, rep range, load, RPE and rest.',
            '',
            ...$this->athleteAndRoutineLines($profile, $goal, $hint),
            '',
            'Slots to progress (day, exercise), all weights in kilograms:',
        ];

        foreach ($outgoing->cycleDays->sortBy('order') as $day) {
            foreach ($day->dayExercises->sortBy('order') as $slot) {
                if ($performed->has($this->slotKey($day->order, $slot->order))) {
                    $lines[] = $this->slotLine(
                        $day,
                        $slot,
                        $progressionSummary[$slot->exercise_id],
                        $recommendationsByExercise->get($slot->exercise_id),
                    );
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Return exactly one progression per listed slot, using the same day and exercise numbers.';

        return implode("\n", $lines);
    }

    /**
     * The athlete-profile / routine-goal-and-hint block shared by both prompts.
     *
     * @return list<string>
     */
    private function athleteAndRoutineLines(AthleteProfile $profile, Goal $goal, ?string $hint): array
    {
        $lines = [
            'Athlete profile:',
            "- Experience level: {$profile->experience_level->value}",
            "- Available days per week: {$profile->days_per_week}",
            "- Target session length: {$profile->session_minutes} minutes",
        ];

        if (filled($profile->notes)) {
            $lines[] = "- Notes: {$profile->notes}";
        }

        $lines[] = '';
        $lines[] = 'Routine:';
        $lines[] = "- Goal: {$goal->value}";

        if (filled($hint)) {
            $lines[] = "- Hint: {$hint}";
        }

        return $lines;
    }

    private function slotLine(CycleDay $day, DayExercise $slot, ExerciseProgressionData $entry, ?ExerciseRecommendation $recommendation): string
    {
        $prescribed = sprintf('%dx%d-%d', $slot->sets, $slot->rep_min, $slot->rep_max);
        $prescribed .= $slot->target_weight_kg !== null ? sprintf(' at %.2fkg', $slot->target_weight_kg) : '';
        $prescribed .= $slot->target_rpe !== null ? sprintf(', RPE %.1f', $slot->target_rpe) : '';

        $actual = sprintf('%.2fkg avg x %.1f reps', $entry->actualAvgWeightKg, $entry->actualAvgReps);
        $actual .= $entry->actualMaxRpe !== null ? sprintf(' (max RPE %.1f)', $entry->actualMaxRpe) : '';

        $line = sprintf(
            '- day %d, exercise %d — %s: prescribed %s; actual %s; trend: %s%s',
            $day->order,
            $slot->order,
            $slot->exercise->name,
            $prescribed,
            $actual,
            $entry->trend,
            $entry->plateauSignal ? ', plateau signal' : '',
        );

        if ($recommendation !== null) {
            $line .= sprintf(
                '; recommendation: %s — %.2fkg, %dx%d-%d — %s',
                $recommendation->action->value,
                $recommendation->target_weight_kg,
                $recommendation->target_sets,
                $recommendation->target_rep_min,
                $recommendation->target_rep_max,
                $recommendation->explanation,
            );
        }

        return $line;
    }

    private function slotKey(int $dayOrder, int $exerciseOrder): string
    {
        return "{$dayOrder}.{$exerciseOrder}";
    }

    /**
     * Validates the AI's progressions against the slots it was asked about —
     * exactly those, each once — and maps each to a prescription.
     *
     * @param  array<string, mixed>  $structured
     * @param  Collection<string, DayExercise>  $performed  keyed by {@see self::slotKey()}
     * @return array<string, CyclePlanExerciseData> keyed by {@see self::slotKey()}
     */
    private function mapProgressions(array $structured, Collection $performed): array
    {
        $progressions = $structured['progressions'] ?? null;

        if (! is_array($progressions) || ! array_is_list($progressions)) {
            throw new CycleGenerationException('The response is missing its progressions list.');
        }

        $mapped = [];

        foreach ($progressions as $progression) {
            if (! is_array($progression)) {
                throw new CycleGenerationException('Each progression must be an object.');
            }

            $key = $this->slotKey($this->requireInt($progression, 'day', min: 1), $this->requireInt($progression, 'exercise', min: 1));
            $slot = $performed->get($key);

            if ($slot === null) {
                throw new CycleGenerationException("The response progresses a slot that was not asked about ({$key}).");
            }

            if (isset($mapped[$key])) {
                throw new CycleGenerationException("The response progresses slot {$key} twice.");
            }

            $mapped[$key] = $this->mapExercise(
                [...$progression, 'name' => $slot->exercise->name, 'primary_muscle_group' => $slot->exercise->primary_muscle_group?->value],
                $slot->exercise_id,
            );
        }

        $missing = $performed->keys()->diff(array_keys($mapped));

        if ($missing->isNotEmpty()) {
            throw new CycleGenerationException('The response is missing progressions for: '.$missing->implode(', ').'.');
        }

        return $mapped;
    }

    /**
     * The outgoing cycle's days and exercises as a plan: each slot takes the
     * progression keyed for it, or — not performed — is copied verbatim.
     *
     * @param  array<string, CyclePlanExerciseData>  $progressions  keyed by {@see self::slotKey()}
     */
    private function cloneOutgoing(Cycle $outgoing, string $splitRationale, array $progressions): CyclePlanData
    {
        return new CyclePlanData(
            splitRationale: $splitRationale,
            days: $outgoing->cycleDays->sortBy('order')->map(fn (CycleDay $day): CyclePlanDayData => new CyclePlanDayData(
                label: $day->label,
                focusMuscleGroups: $day->focus_muscle_groups,
                rationale: $day->rationale,
                exercises: $day->dayExercises->sortBy('order')->map(
                    fn (DayExercise $slot): CyclePlanExerciseData => $progressions[$this->slotKey($day->order, $slot->order)]
                        ?? $this->copyOf($slot),
                )->values()->all(),
            ))->values()->all(),
        );
    }

    private function copyOf(DayExercise $slot): CyclePlanExerciseData
    {
        return new CyclePlanExerciseData(
            name: $slot->exercise->name,
            primaryMuscleGroup: $slot->exercise->primary_muscle_group?->value,
            sets: $slot->sets,
            repMin: $slot->rep_min,
            repMax: $slot->rep_max,
            targetWeightKg: $slot->target_weight_kg !== null ? (float) $slot->target_weight_kg : null,
            targetRpe: $slot->target_rpe !== null ? (float) $slot->target_rpe : null,
            restSeconds: $slot->rest_seconds,
            rationale: $slot->rationale,
            exerciseId: $slot->exercise_id,
        );
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function mapPlan(array $structured, int $minExercises, int $maxExercises): CyclePlanData
    {
        $splitRationale = $this->requireString($structured, 'split_rationale');
        $days = $structured['days'] ?? null;

        if (! is_array($days) || array_is_list($days) === false || count($days) !== self::DAYS_PER_CYCLE) {
            throw new CycleGenerationException(
                'The plan must contain exactly '.self::DAYS_PER_CYCLE.' days, got '.(is_array($days) ? count($days) : 'none').'.'
            );
        }

        return new CyclePlanData(
            splitRationale: $splitRationale,
            days: array_map(
                fn (mixed $day): CyclePlanDayData => $this->mapDay($day, $minExercises, $maxExercises),
                $days,
            ),
        );
    }

    private function mapDay(mixed $day, int $minExercises, int $maxExercises): CyclePlanDayData
    {
        if (! is_array($day)) {
            throw new CycleGenerationException('Each plan day must be an object.');
        }

        $exercises = $day['exercises'] ?? null;

        if (! is_array($exercises)) {
            throw new CycleGenerationException("Day '{$this->requireString($day, 'label')}' is missing its exercises list.");
        }

        $count = count($exercises);

        if ($count < $minExercises || $count > $maxExercises) {
            throw new CycleGenerationException(
                "A day must have between {$minExercises} and {$maxExercises} exercises, got {$count}."
            );
        }

        return new CyclePlanDayData(
            label: $this->requireString($day, 'label'),
            focusMuscleGroups: $this->mapMuscleGroups($day['focus_muscle_groups'] ?? null),
            rationale: $this->requireString($day, 'day_rationale'),
            exercises: array_map(fn (mixed $exercise): CyclePlanExerciseData => $this->mapExercise($exercise), $exercises),
        );
    }

    private function mapExercise(mixed $exercise, ?int $exerciseId = null): CyclePlanExerciseData
    {
        if (! is_array($exercise)) {
            throw new CycleGenerationException('Each exercise must be an object.');
        }

        $repMin = $this->requireInt($exercise, 'rep_min', min: 1);
        $repMax = $this->requireInt($exercise, 'rep_max', min: 1);

        if ($repMin > $repMax) {
            throw new CycleGenerationException("rep_min ({$repMin}) cannot exceed rep_max ({$repMax}).");
        }

        $weight = $exercise['target_weight_kg'] ?? null;

        if (! is_numeric($weight) || (float) $weight < 0) {
            throw new CycleGenerationException('Every prescribed exercise needs a non-negative target_weight_kg.');
        }

        return new CyclePlanExerciseData(
            name: $this->requireString($exercise, 'name'),
            primaryMuscleGroup: $this->optionalMuscleGroup($exercise['primary_muscle_group'] ?? null),
            sets: $this->requireInt($exercise, 'sets', min: 1),
            repMin: $repMin,
            repMax: $repMax,
            targetWeightKg: (float) $weight,
            targetRpe: $this->optionalRpe($exercise['target_rpe'] ?? null),
            restSeconds: $this->requireInt($exercise, 'rest_seconds', min: 0),
            rationale: $this->requireString($exercise, 'rationale'),
            exerciseId: $exerciseId,
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new CycleGenerationException("Missing or empty '{$key}' in the plan.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requireInt(array $source, string $key, int $min): int
    {
        $value = $source[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new CycleGenerationException("'{$key}' must be an integer.");
        }

        $value = (int) $value;

        if ($value < $min) {
            throw new CycleGenerationException("'{$key}' must be at least {$min}.");
        }

        return $value;
    }

    private function optionalRpe(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 10) {
            throw new CycleGenerationException('target_rpe must be between 0 and 10.');
        }

        return (float) $value;
    }

    /**
     * @return list<string>
     */
    private function mapMuscleGroups(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            throw new CycleGenerationException('Each day needs at least one focus muscle group.');
        }

        return array_map(function (mixed $value): string {
            $group = is_string($value) ? MuscleGroup::tryFrom($value) : null;

            if ($group === null) {
                throw new CycleGenerationException('Unknown muscle group in the plan.');
            }

            return $group->value;
        }, array_values($values));
    }

    private function optionalMuscleGroup(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? MuscleGroup::tryFrom($value)?->value : null;
    }
}
