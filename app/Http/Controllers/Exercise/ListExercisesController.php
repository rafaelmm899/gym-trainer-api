<?php

namespace App\Http\Controllers\Exercise;

use App\Enums\Shared\MuscleGroup;
use App\Http\Resources\Exercise\ExerciseResource;
use App\Models\Exercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListExercisesController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->query('q', ''));
        $muscleGroup = MuscleGroup::tryFrom((string) $request->query('muscle_group', ''));

        $query = Exercise::query()->orderBy('name')->limit(50);

        if ($term !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);
        }

        if ($muscleGroup !== null) {
            $query->where('primary_muscle_group', $muscleGroup->value);
        }

        return ExerciseResource::collection($query->get());
    }
}
