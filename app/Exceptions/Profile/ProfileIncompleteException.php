<?php

namespace App\Exceptions\Profile;

use App\Exceptions\DomainException;

/**
 * The user has not completed onboarding (no `athlete_profiles` row), so the AI
 * cycle planner has nothing to work from. Thrown from a Service guard when the
 * user tries to create a routine. HTTP 409 — the request is well-formed, the
 * account state forbids it.
 */
final class ProfileIncompleteException extends DomainException
{
    protected string $errorCode = 'PROFILE_INCOMPLETE';

    public function __construct()
    {
        parent::__construct('Complete your athlete profile before creating a routine.');
    }
}
