<?php

namespace App\Actions\Recommendation;

use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use App\Services\Exercise\ExerciseHistoryService;
use App\Services\Recommendation\RecommendationCatalogService;
use Illuminate\Database\Eloquent\Collection;

final class RoutineRecommendationsListAction
{
    public function __construct(
        private RecommendationCatalogService $catalog,
        private ExerciseHistoryService $history,
    ) {}

    /**
     * @return Collection<int, ExerciseRecommendation>
     */
    public function handle(User $user, Routine $routine): Collection
    {
        $recommendations = $this->catalog->listCurrentForRoutine($routine);

        $this->history->attachTrend($user, $recommendations);

        return $recommendations;
    }
}
