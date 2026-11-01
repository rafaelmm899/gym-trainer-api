<?php

namespace App\Actions\Cycle;

use App\Enums\Cycle\CycleStatus;
use App\Enums\Cycle\DayProgressionState;
use App\Enums\Recommendation\RecommendationStatus;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Models\CycleDay;
use App\Models\ExerciseRecommendation;
use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;
use App\Services\Cycle\ProgressionSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Progresses one day of a cycle N+1 that is `generating`: asks the AI about the
 * slots the athlete trained that day and writes the answer over the day's cloned
 * exercises. While attempts remain a failure is rethrown so the queue retries
 * it; on the last attempt the day falls back to the routine's session
 * recommendations (or last week's prescription), so generation always finishes.
 */
final class CycleDayProgressAction
{
    public function __construct(
        private CyclePlannerService $planner,
        private CycleDraftService $draft,
        private ProgressionSummaryService $progression,
    ) {}

    public function handle(CycleDay $day, bool $isLastAttempt): void
    {
        if ($day->progression_state !== DayProgressionState::Pending) {
            return;
        }

        $routine = $day->cycle()->firstOrFail()->routine()->firstOrFail();
        $outgoing = $routine->cycles()
            ->where('status', CycleStatus::Active)
            ->with('cycleDays.dayExercises.exercise')
            ->sole();
        $profile = $routine->user()->firstOrFail()->athleteProfile()->firstOrFail();

        $recommendations = ExerciseRecommendation::query()
            ->where('routine_id', $routine->id)
            ->where('status', RecommendationStatus::Active)
            ->with('exercise')
            ->get();

        $summary = $this->progression->summarize($routine, $outgoing);
        $daySlots = $this->planner->performedSlots($outgoing, $summary)
            ->filter(fn ($slot, string $key): bool => str_starts_with($key, "{$day->order}."));

        try {
            $progressions = $this->planner->progressDay($profile, $routine->goal, $routine->hint, $outgoing, $daySlots, $recommendations, $summary);
            $state = DayProgressionState::Ai;
        } catch (CycleGenerationException $e) {
            if (! $isLastAttempt) {
                throw $e;
            }

            Log::warning('Cycle progression AI failed; falling back to session recommendations.', ['day' => $day->order, 'exception' => $e]);

            $progressions = $this->planner->progressionsFromRecommendations($daySlots, $recommendations);
            $state = DayProgressionState::Fallback;
        }

        DB::transaction(function () use ($day, $progressions, $state): void {
            $this->draft->applyProgressions($day, $progressions);
            $day->update(['progression_state' => $state]);
        });
    }
}
