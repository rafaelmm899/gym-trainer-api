<?php

namespace App\Http\Controllers\Cycle;

use App\Actions\Cycle\CycleGenerateAction;
use App\Http\Resources\Cycle\CycleResource;
use App\Models\Routine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class GenerateCycleController
{
    public function __invoke(Routine $routine, CycleGenerateAction $action): JsonResponse
    {
        $cycle = $action->handle($routine);

        return CycleResource::make($cycle)->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
