<?php

namespace App\Services\Cycle;

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Data\Cycle\CyclePlanData;
use App\Data\Cycle\CyclePlanDayData;
use App\Data\Cycle\CyclePlanExerciseData;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Enums\Shared\MuscleGroup;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Models\AthleteProfile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Wraps {@see CyclePlannerAgent}: builds the planning prompt from the athlete
 * profile plus the routine's goal and hint, invokes the agent, checks the
 * structured response is a usable 5-day plan, and maps it to {@see CyclePlanData}.
 *
 * Every failure — a provider error or an out-of-bounds response — surfaces as
 * {@see CycleGenerationException}. It runs before any database write, so a
 * failure means the routine is never created.
 */
final class CyclePlannerService
{
    private const DAYS_PER_CYCLE = 5;

    public function planFirstCycle(AthleteProfile $profile, Goal $goal, ?string $hint): CyclePlanData
    {
        [$minExercises, $maxExercises] = $this->exercisesPerDayRange($profile->experience_level);

        try {
            $response = CyclePlannerAgent::make()->prompt(
                $this->buildPrompt($profile, $goal, $hint, $minExercises, $maxExercises)
            );
        } catch (Throwable $e) {
            throw new CycleGenerationException(previous: $e);
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw new CycleGenerationException('The planner did not return a structured plan.');
        }

        return $this->mapPlan($response->toArray(), $minExercises, $maxExercises);
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

        $lines[] = '';
        $lines[] = 'Return exactly 5 training days. All weights are in kilograms.';
        $lines[] = "Prescribe between {$minExercises} and {$maxExercises} exercises on EVERY day "
            .'(this athlete\'s experience level); use the higher end for longer sessions.';

        return implode("\n", $lines);
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

    private function mapExercise(mixed $exercise): CyclePlanExerciseData
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
            throw new CycleGenerationException('Every first-cycle exercise needs a non-negative target_weight_kg.');
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
