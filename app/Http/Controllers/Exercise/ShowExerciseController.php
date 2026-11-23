<?php

namespace App\Http\Controllers\Exercise;

use App\Http\Resources\Exercise\ExerciseDetailResource;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;

final class ShowExerciseController
{
    /**
     * One exercise with its step-by-step instructions.
     *
     * The steps come in the language of the `Accept-Language` request header
     * (`en` or `es`, a regional tag such as `es-AR` counts as `es`). With no
     * header, an unsupported language, or an exercise that has no steps in that
     * language, they come in `en`; an exercise with no instructions returns `[]`.
     * The response carries `Vary: Accept-Language`.
     */
    public function __invoke(Exercise $exercise): JsonResponse
    {
        return ExerciseDetailResource::make($exercise)->response()->header('Vary', 'Accept-Language');
    }
}
