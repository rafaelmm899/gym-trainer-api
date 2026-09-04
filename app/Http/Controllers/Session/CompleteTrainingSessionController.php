<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\SessionCloseAction;
use App\Data\Session\CompleteSessionData;
use App\Http\Requests\Session\CompleteTrainingSessionRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\TrainingSession;

final class CompleteTrainingSessionController
{
    public function __invoke(
        CompleteTrainingSessionRequest $request,
        TrainingSession $session,
        SessionCloseAction $action,
    ): TrainingSessionResource {
        $session = $action->handle($session, CompleteSessionData::from($request->validated()));

        return TrainingSessionResource::make($session);
    }
}
