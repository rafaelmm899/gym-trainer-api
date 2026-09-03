<?php

namespace App\Data\Session;

use Spatie\LaravelData\Data;

final class CreateTrainingSessionData extends Data
{
    public function __construct(
        public readonly ?string $day = null,
    ) {}
}
