<?php

namespace App\Data\Session;

use Spatie\LaravelData\Data;

final class UpdateSetLogData extends Data
{
    public function __construct(
        public readonly float $weight_kg,
        public readonly int $reps,
        public readonly ?float $rpe = null,
        public readonly ?string $note = null,
    ) {}
}
