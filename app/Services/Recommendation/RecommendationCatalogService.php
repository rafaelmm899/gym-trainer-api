<?php

namespace App\Services\Recommendation;

use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use Illuminate\Database\Eloquent\Collection;

final class RecommendationCatalogService
{
    /**
     * The routine's recommendations for exercises still present in its
     * current cycle. A recommendation left over for an exercise dropped in a
     * later cycle is excluded.
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
            ->whereIn('exercise_id', $exerciseIds)
            ->with('exercise')
            ->get()
            ->sortBy(fn (ExerciseRecommendation $recommendation): string => $recommendation->exercise->name)
            ->values();
    }
}
