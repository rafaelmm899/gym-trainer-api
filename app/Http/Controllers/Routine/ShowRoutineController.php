<?php

namespace App\Http\Controllers\Routine;

use App\Http\Resources\Routine\RoutineResource;
use App\Models\Routine;

final class ShowRoutineController
{
    public function __invoke(Routine $routine): RoutineResource
    {
        return RoutineResource::make($routine);
    }
}
