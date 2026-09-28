<?php

namespace App\Http\Controllers\Session;

use App\Enums\Session\SessionStatus;
use App\Http\Requests\Session\ListTrainingSessionsRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\Routine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListTrainingSessionsController
{
    public function __invoke(ListTrainingSessionsRequest $request, Routine $routine): AnonymousResourceCollection
    {
        $sessions = $routine->trainingSessions()
            ->with('cycleDay')
            ->when(
                $request->enum('status', SessionStatus::class),
                fn ($query, SessionStatus $status) => $query->where('status', $status),
            )
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return TrainingSessionResource::collection($sessions);
    }
}
