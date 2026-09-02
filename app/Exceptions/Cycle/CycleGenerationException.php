<?php

namespace App\Exceptions\Cycle;

use App\Exceptions\DomainException;
use App\Services\Cycle\CyclePlannerService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The AI planner could not produce a usable first cycle — the provider call
 * failed, or the structured response was malformed / out of bounds.
 *
 * Thrown from {@see CyclePlannerService}, before any
 * database write, so `RoutineCreateAction` never opens its transaction: the
 * routine is not created and the incumbent active routine is not archived. The
 * `ApiExceptionRenderer` `DomainException` branch renders it as
 * `{ "data": { "code": "AI_GENERATION_FAILED", "message": … } }` with HTTP 502.
 */
final class CycleGenerationException extends DomainException
{
    protected string $errorCode = 'AI_GENERATION_FAILED';

    protected int $statusCode = Response::HTTP_BAD_GATEWAY;

    public function __construct(string $message = 'The training plan could not be generated. Please try again.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
