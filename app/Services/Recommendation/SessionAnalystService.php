<?php

namespace App\Services\Recommendation;

use App\Ai\Agents\Recommendation\SessionAnalystAgent;
use App\Data\Recommendation\ExerciseRecommendationData;
use App\Enums\Recommendation\RecommendationAction;
use App\Exceptions\Recommendation\SessionAnalysisException;
use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use App\Models\SetLog;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Wraps {@see SessionAnalystAgent}: for every exercise trained in a completed
 * session, gathers its baseline (the existing {@see ExerciseRecommendation} for
 * this `(user, routine, exercise)`, or failing that the session's `cycle_day`
 * prescription, or nothing) plus the sets actually logged, builds one prompt
 * covering the whole session, invokes the agent, and validates and maps the
 * structured response back to one {@see ExerciseRecommendationData} per
 * exercise — matched by array position, never by an AI-supplied identifier.
 *
 * Every failure surfaces as {@see SessionAnalysisException}.
 */
final class SessionAnalystService
{
    /**
     * @return list<ExerciseRecommendationData>
     */
    public function analyze(TrainingSession $session): array
    {
        $session->loadMissing(['sets.exercise', 'cycleDay.dayExercises']);

        $entries = $this->buildEntries($session);

        try {
            $response = SessionAnalystAgent::make()->prompt($this->buildPrompt($session, $entries));
        } catch (Throwable $e) {
            throw new SessionAnalysisException(previous: $e);
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw new SessionAnalysisException('The analyst did not return a structured result.');
        }

        return $this->mapResponse($response->toArray(), $entries);
    }

    /**
     * One entry per distinct exercise trained in the session, in the order the
     * exercise first appears (by `set_number`).
     *
     * @return Collection<int, array{exercise_id: int, name: string, baseline: string, sets: Collection<int, SetLog>}>
     */
    private function buildEntries(TrainingSession $session): Collection
    {
        $existingRecommendations = ExerciseRecommendation::query()
            ->where('user_id', $session->user_id)
            ->where('routine_id', $session->routine_id)
            ->whereIn('exercise_id', $session->sets->pluck('exercise_id')->unique())
            ->get()
            ->keyBy('exercise_id');

        $dayExercisesByExerciseId = $session->cycleDay?->dayExercises->keyBy('exercise_id') ?? new Collection;

        return $session->sets
            ->sortBy('set_number')
            ->groupBy('exercise_id')
            ->map(function (Collection $sets, int $exerciseId) use ($existingRecommendations, $dayExercisesByExerciseId): array {
                /** @var Collection<int, SetLog> $sets */
                return [
                    'exercise_id' => $exerciseId,
                    'name' => (string) $sets->first()->exercise->name,
                    'baseline' => $this->describeBaseline($existingRecommendations->get($exerciseId), $dayExercisesByExerciseId->get($exerciseId)),
                    'sets' => $sets->values(),
                ];
            })
            ->values();
    }

    private function describeBaseline(?ExerciseRecommendation $recommendation, ?DayExercise $dayExercise): string
    {
        if ($recommendation !== null) {
            return sprintf(
                'Previous recommendation for this exercise in this routine: %.2fkg, %d sets, %d-%d reps (last action: %s).',
                $recommendation->target_weight_kg,
                $recommendation->target_sets,
                $recommendation->target_rep_min,
                $recommendation->target_rep_max,
                $recommendation->action->value,
            );
        }

        if ($dayExercise !== null) {
            $weight = $dayExercise->target_weight_kg !== null
                ? sprintf(', target %.2fkg', $dayExercise->target_weight_kg)
                : '';

            return sprintf(
                "Today's original prescription (no prior recommendation yet): %d sets, %d-%d reps%s.",
                $dayExercise->sets,
                $dayExercise->rep_min,
                $dayExercise->rep_max,
                $weight,
            );
        }

        return 'No baseline — first time this exercise is trained in this routine.';
    }

    /**
     * @param  Collection<int, array{exercise_id: int, name: string, baseline: string, sets: Collection<int, SetLog>}>  $entries
     */
    private function buildPrompt(TrainingSession $session, Collection $entries): string
    {
        $lines = ['Analyze this completed training session and decide a next-time target for every exercise below.'];

        if (filled($session->note)) {
            $lines[] = "Session note: {$session->note}";
        }

        if ($session->perceived_effort !== null) {
            $lines[] = "Perceived effort for the whole session: {$session->perceived_effort}/5.";
        }

        foreach ($entries as $entry) {
            $lines[] = '';
            $lines[] = "Exercise: {$entry['name']}";
            $lines[] = $entry['baseline'];
            $lines[] = 'Sets performed this session:';

            foreach ($entry['sets'] as $index => $set) {
                /** @var SetLog $set */
                $line = sprintf('%d. %.2fkg x %d reps', $index + 1, $set->weight_kg, $set->reps);

                if ($set->rpe !== null) {
                    $line .= sprintf(' (RPE %.1f)', $set->rpe);
                }

                if (filled($set->note)) {
                    $line .= " — note: {$set->note}";
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  Collection<int, array{exercise_id: int, name: string, baseline: string, sets: Collection<int, SetLog>}>  $entries
     * @return list<ExerciseRecommendationData>
     */
    private function mapResponse(array $structured, Collection $entries): array
    {
        $recommendations = $structured['recommendations'] ?? null;

        if (! is_array($recommendations) || array_is_list($recommendations) === false || count($recommendations) !== $entries->count()) {
            throw new SessionAnalysisException(sprintf(
                'Expected %d recommendation(s), got %s.',
                $entries->count(),
                is_array($recommendations) ? (string) count($recommendations) : 'none',
            ));
        }

        return $entries->values()
            ->map(fn (array $entry, int $index) => $this->mapRecommendation($entry['exercise_id'], $recommendations[$index]))
            ->all();
    }

    private function mapRecommendation(int $exerciseId, mixed $data): ExerciseRecommendationData
    {
        if (! is_array($data)) {
            throw new SessionAnalysisException('Each recommendation must be an object.');
        }

        $weight = $data['target_weight_kg'] ?? null;

        if (! is_numeric($weight) || (float) $weight < 0) {
            throw new SessionAnalysisException('Every recommendation needs a non-negative target_weight_kg.');
        }

        $sets = $this->requireInt($data, 'target_sets', min: 1);
        $repMin = $this->requireInt($data, 'target_rep_min', min: 1);
        $repMax = $this->requireInt($data, 'target_rep_max', min: 1);

        if ($repMin > $repMax) {
            throw new SessionAnalysisException("target_rep_min ({$repMin}) cannot exceed target_rep_max ({$repMax}).");
        }

        $action = is_string($data['action'] ?? null) ? RecommendationAction::tryFrom($data['action']) : null;

        if ($action === null) {
            throw new SessionAnalysisException('Unknown or missing action in the recommendation.');
        }

        $explanation = $data['explanation'] ?? null;

        if (! is_string($explanation) || trim($explanation) === '') {
            throw new SessionAnalysisException('Every recommendation needs a non-empty explanation.');
        }

        return new ExerciseRecommendationData(
            exerciseId: $exerciseId,
            targetWeightKg: (float) $weight,
            targetSets: $sets,
            targetRepMin: $repMin,
            targetRepMax: $repMax,
            action: $action,
            explanation: trim($explanation),
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requireInt(array $source, string $key, int $min): int
    {
        $value = $source[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new SessionAnalysisException("'{$key}' must be an integer.");
        }

        $value = (int) $value;

        if ($value < $min) {
            throw new SessionAnalysisException("'{$key}' must be at least {$min}.");
        }

        return $value;
    }
}
