<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The session has no sets logged. It cannot be completed with nothing to
 * analyse. HTTP 422 (not the `DomainException` default of 409) — the AC for
 * this guard explicitly mandates it.
 */
final class SessionHasNoSetsException extends DomainException
{
    protected string $errorCode = 'SESSION_HAS_NO_SETS';

    protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function __construct()
    {
        parent::__construct('This session has no sets logged. Log at least one before completing it.');
    }
}
