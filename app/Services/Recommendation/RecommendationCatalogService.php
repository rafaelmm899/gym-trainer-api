<?php

namespace App\Services\Recommendation;

use App\Enums\Recommendation\RecommendationStatus;
use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use Illuminate\Database\Eloquent\Collection;

final class RecommendationCatalogService
{
    /**
     * The routine's `active` recommendations for exercises still present in
     * its current cycle. Excluded: a recommendation left over for an exercise
     * dropped in a later cycle, and one already `applied` by a cycle rollover.
     *
     * @return Collection<int, ExerciseRecommendation>
     */
    public function listCurrentForRoutine(Routine $routine): Collection
    {
        $cycle = $routine->cycle;

        if ($cycle === null) {
            return new Collection;
        }

        $exerciseIds = DayExercise::whereHas('cycleDay', fn ($query) => $query->where('cycle_id', $cycle->id))
            ->pluck('exercise_id');

        return ExerciseRecommendation::where('routine_id', $routine->id)
            ->where('status', RecommendationStatus::Active)
            ->whereIn('exercise_id', $exerciseIds)
            ->with('exercise')
            ->get()
            ->sortBy(fn (ExerciseRecommendation $recommendation): string => $recommendation->exercise->name)
            ->values();
    }
}
