<?php

namespace App\Http\Controllers\Routine;

use App\Actions\Routine\RoutineCreateAction;
use App\Data\Routine\RoutineData;
use App\Http\Requests\Routine\StoreRoutineRequest;
use App\Http\Resources\Routine\RoutineResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class StoreRoutineController
{
    public function __invoke(StoreRoutineRequest $request, RoutineCreateAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $routine = $action->handle($user, RoutineData::from($request->validated()));

        return RoutineResource::make($routine)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
