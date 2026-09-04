<?php

namespace App\Data\Session;

use Spatie\LaravelData\Data;

final class CompleteSessionData extends Data
{
    public function __construct(
        public readonly ?string $note = null,
        public readonly ?int $perceived_effort = null,
    ) {}
}
