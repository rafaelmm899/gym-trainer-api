<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\TrainingSessionShowAction;
use App\Http\Requests\Session\ShowTrainingSessionRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\TrainingSession;
use App\Models\User;

final class ShowTrainingSessionController
{
    public function __invoke(
        ShowTrainingSessionRequest $request,
        TrainingSession $session,
        TrainingSessionShowAction $action,
    ): TrainingSessionResource {
        /** @var User $user */
        $user = $request->user();

        return TrainingSessionResource::make($action->handle($user, $session));
    }
}
