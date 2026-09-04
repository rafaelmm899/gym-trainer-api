<?php

namespace App\Http\Controllers\Recommendation;

use App\Http\Resources\Recommendation\ExerciseRecommendationResource;
use App\Models\Routine;
use App\Services\Recommendation\RecommendationCatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListRoutineRecommendationsController
{
    public function __invoke(Routine $routine, RecommendationCatalogService $service): AnonymousResourceCollection
    {
        return ExerciseRecommendationResource::collection($service->listCurrentForRoutine($routine));
    }
}
