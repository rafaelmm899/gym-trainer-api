<?php

namespace App\Services\Cycle;

use App\Data\Cycle\ExerciseProgressionData;
use App\Enums\Session\SessionStatus;
use App\Models\Cycle;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Builds the per-exercise progression summary fed to the N+1 planner: for
 * every exercise prescribed in the outgoing cycle, prescribed vs. actual
 * (from logged sets), a `performed` flag, and a weight trend + plateau signal
 * from the exercise's last two completed sessions across the whole routine.
 *
 * Pure PHP — no AI, no writes. `actual*` and `trend` always read from {@see
 * SetLog}, never from the exercise's standing recommendation, so real
 * performance (even one that exceeds the recommended target) is what reaches
 * the planner.
 */
final class ProgressionSummaryService
{
    /**
     * @return array<int, ExerciseProgressionData> keyed by exercise id
     */
    public function summarize(Routine $routine, Cycle $cycle): array
    {
        $cycle->loadMissing('cycleDays');

        $cycleDayIds = $cycle->cycleDays->pluck('id');

        $prescriptions = DayExercise::query()
            ->whereIn('cycle_day_id', $cycleDayIds)
            ->with('exercise')
            ->get()
            ->unique('exercise_id')
            ->values();

        return $prescriptions
            ->mapWithKeys(fn (DayExercise $prescription): array => [
                $prescription->exercise_id => $this->summarizeExercise($routine, $cycleDayIds, $prescription),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, int>  $cycleDayIds
     */
    private function summarizeExercise(Routine $routine, Collection $cycleDayIds, DayExercise $prescription): ExerciseProgressionData
    {
        $exerciseId = $prescription->exercise_id;

        $setsThisCycle = SetLog::query()
            ->where('exercise_id', $exerciseId)
            ->whereHas('session', fn ($query) => $query
                ->where('status', SessionStatus::Completed)
                ->whereIn('cycle_day_id', $cycleDayIds))
            ->get();

        $performed = $setsThisCycle->isNotEmpty();

        [$trend, $plateauSignal] = $this->trend($routine, $exerciseId);

        return new ExerciseProgressionData(
            exerciseId: $exerciseId,
            exerciseName: $prescription->exercise->name,
            prescribedSets: $prescription->sets,
            prescribedRepMin: $prescription->rep_min,
            prescribedRepMax: $prescription->rep_max,
            prescribedWeightKg: $prescription->target_weight_kg !== null ? (float) $prescription->target_weight_kg : null,
            performed: $performed,
            actualAvgWeightKg: $performed ? round((float) $setsThisCycle->avg('weight_kg'), 2) : null,
            actualAvgReps: $performed ? (float) $setsThisCycle->avg('reps') : null,
            actualMaxRpe: $performed ? $this->maxRpe($setsThisCycle) : null,
            trend: $trend,
            plateauSignal: $plateauSignal,
        );
    }

    /**
     * The exercise's last two *completed* sessions, routine-wide (any cycle),
     * compared by average logged weight. Fewer than two on record →
     * `insufficient_data`: a single outgoing cycle rarely trains the same
     * exercise twice, so the useful signal needs sessions that may span
     * cycles.
     *
     * @return array{0: 'up'|'down'|'flat'|'insufficient_data', 1: bool}
     */
    private function trend(Routine $routine, int $exerciseId): array
    {
        $sessionAverages = TrainingSession::query()
            ->where('routine_id', $routine->id)
            ->where('status', SessionStatus::Completed)
            ->whereHas('sets', fn ($query) => $query->where('exercise_id', $exerciseId))
            ->with(['sets' => fn ($query) => $query->where('exercise_id', $exerciseId)])
            ->orderByDesc('completed_at')
            ->limit(2)
            ->get()
            ->map(fn (TrainingSession $session): float => (float) $session->sets->avg('weight_kg'))
            ->reverse()
            ->values();

        if ($sessionAverages->count() < 2) {
            return ['insufficient_data', false];
        }

        [$older, $newer] = $sessionAverages->all();

        $trend = match (true) {
            $newer > $older => 'up',
            $newer < $older => 'down',
            default => 'flat',
        };

        return [$trend, $trend === 'down' || $trend === 'flat'];
    }

    /**
     * @param  Collection<int, SetLog>  $sets
     */
    private function maxRpe(Collection $sets): ?float
    {
        $values = $sets->pluck('rpe')
            ->reject(fn (mixed $rpe): bool => $rpe === null)
            ->map(fn (mixed $rpe): float => (float) $rpe);

        return $values->isEmpty() ? null : $values->max();
    }
}
