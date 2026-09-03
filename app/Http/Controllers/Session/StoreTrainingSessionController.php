<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\TrainingSessionCreateAction;
use App\Data\Session\CreateTrainingSessionData;
use App\Http\Requests\Session\StoreTrainingSessionRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class StoreTrainingSessionController
{
    public function __invoke(
        StoreTrainingSessionRequest $request,
        Routine $routine,
        TrainingSessionCreateAction $action,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $session = $action->handle($user, $routine, CreateTrainingSessionData::from($request->validated()));

        return TrainingSessionResource::make($session)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
