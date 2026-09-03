<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * The user already has an open (`in_progress`) training session. They train one
 * day at a time — the current session must be completed before another is
 * opened. HTTP 409 — the request is well-formed, the account state forbids it.
 */
final class SessionInProgressException extends DomainException
{
    protected string $errorCode = 'SESSION_IN_PROGRESS';

    public function __construct()
    {
        parent::__construct('Finish your current training session before starting another.');
    }
}
