<?php

namespace App\Data\Routine;

use App\Enums\Shared\Goal;
use Spatie\LaravelData\Data;

final class RoutineData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly Goal $goal,
        public readonly ?string $hint = null,
    ) {}
}
