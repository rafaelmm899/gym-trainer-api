<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\SetLogCreateAction;
use App\Data\Session\LogSetData;
use App\Http\Requests\Session\LogSetRequest;
use App\Http\Resources\Session\SetLogResource;
use App\Models\TrainingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class LogSetController
{
    public function __invoke(
        LogSetRequest $request,
        TrainingSession $session,
        SetLogCreateAction $action,
    ): JsonResponse {
        $set = $action->handle($session, LogSetData::from($request->validated()));

        return SetLogResource::make($set)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
