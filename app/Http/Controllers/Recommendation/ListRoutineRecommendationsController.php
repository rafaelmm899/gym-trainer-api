<?php

namespace App\Http\Controllers\Recommendation;

use App\Actions\Recommendation\RoutineRecommendationsListAction;
use App\Http\Resources\Recommendation\ExerciseRecommendationResource;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListRoutineRecommendationsController
{
    public function __invoke(Request $request, Routine $routine, RoutineRecommendationsListAction $action): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ExerciseRecommendationResource::collection($action->handle($user, $routine));
    }
}
