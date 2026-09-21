<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * A `completed` `TrainingSession` already exists for this `cycle_day` in the
 * routine's active cycle. The day was already trained; importing another
 * filled sheet for it would double-log it. HTTP 409 — the request is
 * well-formed and the day is owned; its state forbids the write. Specific to
 * the import flow — `TrainingSessionOpeningService` (shared with
 * `POST .../sessions`) does not check this.
 */
final class CycleDayAlreadyCompletedException extends DomainException
{
    protected string $errorCode = 'CYCLE_DAY_ALREADY_COMPLETED';

    public function __construct()
    {
        parent::__construct('This day has already been completed for the active cycle.');
    }
}
