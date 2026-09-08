<?php

namespace App\Exceptions\Cycle;

use App\Exceptions\DomainException;
use App\Services\Cycle\CycleDayCsvExportService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The routine whose day is being exported has no `active` cycle — its
 * highest-`sequence_number` cycle is `generating` / `failed` / `completed` /
 * `incomplete`, or the routine itself is `archived`. Thrown from
 * {@see CycleDayCsvExportService} before anything is streamed. HTTP 422: the
 * request is well-formed and the routine is owned, but there is nothing to
 * export.
 */
final class RoutineHasNoActiveCycleException extends DomainException
{
    protected string $errorCode = 'ROUTINE_HAS_NO_ACTIVE_CYCLE';

    protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function __construct()
    {
        parent::__construct('This routine has no active cycle to export.');
    }
}
