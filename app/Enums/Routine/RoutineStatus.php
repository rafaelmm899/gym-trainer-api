<?php

namespace App\Enums\Routine;

enum RoutineStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
