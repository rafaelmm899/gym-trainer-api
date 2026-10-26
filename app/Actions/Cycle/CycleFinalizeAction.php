<?php

namespace App\Actions\Cycle;

use App\Enums\Cycle\CycleStatus;
use App\Enums\Cycle\DayProgressionState;
use App\Enums\Recommendation\RecommendationStatus;
use App\Models\Cycle;
use App\Models\ExerciseRecommendation;
use App\Services\Cycle\CycleCompletionService;
use App\Services\Cycle\ProgressionSummaryService;
use Illuminate\Support\Facades\DB;

/**
 * Last step of generating cycle N+1, once every day has its prescription: one
 * transaction that activates the new cycle, rolls the outgoing cycle to
 * `completed`/`incomplete` and marks the recommendations of the trained
 * exercises `applied`. A no-op unless the cycle is still `generating`.
 */
final class CycleFinalizeAction
{
    public function __construct(
        private ProgressionSummaryService $progression,
        private CycleCompletionService $completion,
    ) {}

    public function handle(Cycle $newCycle): void
    {
        if ($newCycle->status !== CycleStatus::Generating) {
            return;
        }

        $routine = $newCycle->routine()->firstOrFail();
        $outgoing = $routine->cycles()->where('status', CycleStatus::Active)->sole();
        $summary = $this->progression->summarize($routine, $outgoing);

        $days = $newCycle->cycleDays()->get();
        $aiDays = $days->where('progression_state', DayProgressionState::Ai)->count();
        $trainedDays = $aiDays + $days->where('progression_state', DayProgressionState::Fallback)->count();

        $splitRationale = $trainedDays === 0
            ? 'No exercise was trained last cycle; the prescription is unchanged.'
            : "Progressed {$aiDays} of {$trainedDays} trained days with the AI; the rest used your session recommendations or kept last week's prescription.";

        DB::transaction(function () use ($newCycle, $outgoing, $routine, $summary, $splitRationale): void {
            $newCycle->update([
                'status' => CycleStatus::Active,
                'split_rationale' => $splitRationale,
                'activated_at' => now(),
            ]);

            $outgoing->update([
                'status' => $this->completion->wasCompleted($outgoing)
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
        });
    }
}
