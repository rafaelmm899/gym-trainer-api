<?php

namespace App\Http\Controllers\Cycle;

use App\Actions\Session\TrainingSessionImportAction;
use App\Http\Requests\Cycle\ImportCycleDayRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

final class ImportCycleDayController
{
    public function __invoke(
        ImportCycleDayRequest $request,
        Routine $routine,
        CycleDay $day,
        TrainingSessionImportAction $action,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        /** @var UploadedFile $file */
        $file = $request->validated('file');

        $session = $action->handle($user, $routine, $day, $file);

        return TrainingSessionResource::make($session)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
