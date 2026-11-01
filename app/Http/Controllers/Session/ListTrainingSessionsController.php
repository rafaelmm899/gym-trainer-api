<?php

namespace App\Http\Controllers\Session;

use App\Actions\Session\TrainingSessionListAction;
use App\Enums\Session\SessionStatus;
use App\Http\Requests\Session\ListTrainingSessionsRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\Routine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListTrainingSessionsController
{
    public function __invoke(
        ListTrainingSessionsRequest $request,
        Routine $routine,
        TrainingSessionListAction $action,
    ): AnonymousResourceCollection {
        return TrainingSessionResource::collection($action->handle(
            $routine,
            $request->input('cycle_day'),
            $request->enum('status', SessionStatus::class),
            $request->integer('per_page', 15),
        ));
    }
}
