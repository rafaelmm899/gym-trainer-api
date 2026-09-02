<?php

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

final class LoginData extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}
}
