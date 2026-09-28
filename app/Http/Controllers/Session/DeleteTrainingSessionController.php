<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\TrainingSessionDeleteAction;
use App\Http\Requests\Session\DeleteTrainingSessionRequest;
use App\Models\TrainingSession;
use Illuminate\Http\Response;

final class DeleteTrainingSessionController
{
    public function __invoke(
        DeleteTrainingSessionRequest $request,
        TrainingSession $session,
        TrainingSessionDeleteAction $action,
    ): Response {
        $action->handle($session);

        return response()->noContent();
    }
}
