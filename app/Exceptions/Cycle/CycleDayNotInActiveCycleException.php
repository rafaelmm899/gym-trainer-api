<?php

namespace App\Exceptions\Cycle;

use App\Exceptions\DomainException;
use App\Services\Cycle\CycleDayCsvExportService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `{day}` uuid in the export URL is a real `cycle_days` row, but it does not
 * belong to this routine's active cycle — it is a day of another routine, or of
 * an older / newer cycle of this one. Thrown from
 * {@see CycleDayCsvExportService} after the active-cycle check.
 *
 * A distinct class from {@see \App\Exceptions\Session\CycleDayNotInActiveCycleException}
 * — same rule, different domain, per the codebase's "folders by domain"
 * convention. That one is HTTP 409 for the session-open flow (a state
 * conflict); this one is HTTP 422 because the export treats a stale / foreign
 * `{day}` as an unprocessable path parameter.
 */
final class CycleDayNotInActiveCycleException extends DomainException
{
    protected string $errorCode = 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE';

    protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function __construct()
    {
        parent::__construct("That day does not belong to this routine's active cycle.");
    }
}
