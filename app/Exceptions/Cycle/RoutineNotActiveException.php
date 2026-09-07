<?php

namespace App\Exceptions\Cycle;

use App\Exceptions\DomainException;

/**
 * A cycle can only be generated for the caller's active routine. The routine
 * in the URL is `archived` — its history stays readable, but no new cycle can
 * be generated for it. HTTP 409 — the request is well-formed and the routine
 * is owned; the state forbids it.
 *
 * A separate class from {@see \App\Exceptions\Session\RoutineNotActiveException}
 * — same rule, different domain, per the codebase's "folders by domain"
 * convention for exceptions.
 */
final class RoutineNotActiveException extends DomainException
{
    protected string $errorCode = 'ROUTINE_NOT_ACTIVE';

    public function __construct()
    {
        parent::__construct('This routine is archived. A cycle can only be generated for your active routine.');
    }
}
