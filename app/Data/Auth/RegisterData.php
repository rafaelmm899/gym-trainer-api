<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

final class RegisterData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
    ) {}
}
