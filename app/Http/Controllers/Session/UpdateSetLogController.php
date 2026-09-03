<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\SetLogUpdateAction;
use App\Data\Session\UpdateSetLogData;
use App\Http\Requests\Session\UpdateSetLogRequest;
use App\Http\Resources\Session\SetLogResource;
use App\Models\SetLog;
use App\Models\TrainingSession;

final class UpdateSetLogController
{
    public function __invoke(
        UpdateSetLogRequest $request,
        TrainingSession $session,
        SetLog $set,
        SetLogUpdateAction $action,
    ): SetLogResource {
        return SetLogResource::make(
            $action->handle($session, $set, UpdateSetLogData::from($request->validated())),
        );
    }
}
