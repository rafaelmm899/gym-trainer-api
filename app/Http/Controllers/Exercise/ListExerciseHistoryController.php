<?php

namespace App\Http\Controllers\Exercise;

use App\Actions\Exercise\ExerciseHistoryListAction;
use App\Http\Requests\Exercise\ListExerciseHistoryRequest;
use App\Http\Resources\Exercise\ExerciseHistoryItemResource;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListExerciseHistoryController
{
    public function __invoke(
        ListExerciseHistoryRequest $request,
        Exercise $exercise,
        ExerciseHistoryListAction $action,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return ExerciseHistoryItemResource::collection(
            $action->handle($user, $exercise, $request->input('routine'), $request->integer('per_page', 10)),
        );
    }
}
