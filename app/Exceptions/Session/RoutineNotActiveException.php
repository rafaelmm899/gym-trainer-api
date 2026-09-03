<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * A training session can only be logged against the user's active routine. The
 * routine in the URL is `archived` — its history stays readable, but nothing
 * can be added to it. HTTP 409 — the request is well-formed and the routine is
 * owned; the state forbids it.
 */
final class RoutineNotActiveException extends DomainException
{
    protected string $errorCode = 'ROUTINE_NOT_ACTIVE';

    public function __construct()
    {
        parent::__construct('This routine is archived. Sessions can only be logged against your active routine.');
    }
}
