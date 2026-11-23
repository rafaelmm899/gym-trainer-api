<?php

namespace App\Http\Controllers\Exercise;

use App\Http\Resources\Exercise\ExerciseDetailResource;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;

final class ShowExerciseController
{
    /**
     * One exercise with its instructions in the language of `Accept-Language`
     * (`en` or `es`; `en` by default).
     */
    public function __invoke(Exercise $exercise): JsonResponse
    {
        return ExerciseDetailResource::make($exercise)->response()->header('Vary', 'Accept-Language');
    }
}
