<?php

namespace App\Data\Session;

use Spatie\LaravelData\Data;

final class LogSetData extends Data
{
    public function __construct(
        public readonly int $set_number,
        public readonly float $weight_kg,
        public readonly int $reps,
        public readonly ?string $day_exercise_id = null,
        public readonly ?string $exercise_id = null,
        public readonly ?float $rpe = null,
        public readonly ?string $note = null,
    ) {}
}
