<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * The training session is `completed`. Its sets can no longer be logged or
 * corrected — the day is closed. HTTP 409 — the request is well-formed and the
 * session is owned; its state forbids the write.
 */
final class SessionAlreadyCompletedException extends DomainException
{
    protected string $errorCode = 'SESSION_ALREADY_COMPLETED';

    public function __construct()
    {
        parent::__construct('This session is completed. Its sets can no longer be changed.');
    }
}
