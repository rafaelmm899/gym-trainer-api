<?php

namespace App\Http\Controllers\Routine;

use App\Http\Resources\Routine\RoutineResource;
use App\Models\Routine;

final class ShowRoutineController
{
    public function __invoke(Routine $routine): RoutineResource
    {
        $routine->load('cycle.cycleDays.dayExercises.exercise');

        return RoutineResource::make($routine);
    }
}
