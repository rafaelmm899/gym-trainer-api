<?php

namespace App\Actions\Cycle;

use App\Actions\Routine\RoutineCreateAction;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Recommendation\RecommendationStatus;
use App\Enums\Routine\RoutineStatus;
use App\Exceptions\Cycle\RoutineNotActiveException;
use App\Models\Cycle;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Services\Cycle\CycleCompletionService;
use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;
use App\Services\Cycle\ProgressionSummaryService;
use Illuminate\Support\Facades\DB;

/**
 * Generates cycle N+1 for a routine's active cycle, synchronously and
 * all-or-nothing — the same shape as {@see RoutineCreateAction}:
 * guard → plan (outside any transaction) → one transaction that creates the
 * new cycle, persists its days, rolls the outgoing cycle to
 * `completed`/`incomplete`, and marks trained-exercise recommendations
 * `applied`.
 */
final class CycleGenerateAction
{
    public function __construct(
        private CyclePlannerService $planner,
        private CycleDraftService $draft,
        private ProgressionSummaryService $progression,
        private CycleCompletionService $completion,
    ) {}

    public function handle(Routine $routine): Cycle
    {
        $outgoingCycle = $this->ensureRoutineActive($routine);

        $profile = $routine->user()->firstOrFail()->athleteProfile()->firstOrFail();

        $recommendations = ExerciseRecommendation::query()
            ->where('routine_id', $routine->id)
            ->where('status', RecommendationStatus::Active)
            ->with('exercise')
            ->get();

        $summary = $this->progression->summarize($routine, $outgoingCycle);

        // The AI call happens before any transaction — same reasoning as
        // RoutineCreateAction/CyclePlannerService: an external call never runs
        // inside an open transaction.
        $plan = $this->planner->planNextCycle($profile, $routine->goal, $routine->hint, $recommendations, $summary);

        return DB::transaction(function () use ($routine, $outgoingCycle, $plan, $summary): Cycle {
            $newCycle = $routine->cycles()->create([
                'sequence_number' => $outgoingCycle->sequence_number + 1,
                'status' => CycleStatus::Active,
                'split_rationale' => $plan->splitRationale,
                'generated_at' => now(),
                'activated_at' => now(),
            ]);

            $this->draft->persistDays($newCycle, $plan);

            $outgoingCycle->update([
                'status' => $this->completion->wasCompleted($outgoingCycle)
                    ? CycleStatus::Completed
                    : CycleStatus::Incomplete,
                'completed_at' => now(),
            ]);

            $trainedExerciseIds = collect($summary)
                ->filter(fn ($entry): bool => $entry->performed)
                ->keys();

            ExerciseRecommendation::query()
                ->where('routine_id', $routine->id)
                ->whereIn('exercise_id', $trainedExerciseIds)
                ->update(['status' => RecommendationStatus::Applied]);

            return $newCycle->load('cycleDays.dayExercises.exercise');
        });
    }

    /**
     * A cycle can only be generated for the caller's active routine. Returns
     * the routine's active cycle so the caller can compute the next
     * `sequence_number` and run the rollover against it without a second
     * query. `sole()` is deliberate: by this point the routine is confirmed
     * active, so zero or more than one active cycle is an invariant
     * violation, not a case to handle gracefully.
     */
    private function ensureRoutineActive(Routine $routine): Cycle
    {
        throw_unless($routine->status === RoutineStatus::Active, new RoutineNotActiveException);

        return Cycle::query()
            ->where('routine_id', $routine->id)
            ->where('status', CycleStatus::Active)
            ->sole();
    }
}
