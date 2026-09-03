<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * The `day` sent for a planned session is not a day of the routine's active
 * cycle — it belongs to another routine, to an older cycle, or the routine's
 * current cycle is not `active`. The uuid is well-formed and the row exists
 * (validated in the Form Request); the cross-entity state forbids it. HTTP 409.
 */
final class CycleDayNotInActiveCycleException extends DomainException
{
    protected string $errorCode = 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE';

    public function __construct()
    {
        parent::__construct('That day does not belong to this routine\'s active cycle.');
    }
}
