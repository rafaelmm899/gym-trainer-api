<?php

namespace App\Exceptions;

use App\Enums\Shared\ErrorCode;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for a business-rule violation raised from a Service guard clause.
 *
 * A subclass sets {@see $errorCode} — the machine-readable identifier the client
 * branches on, surfaced as the response `code` — and, when 409 is not the right
 * status, {@see $statusCode}. The exception handler renders it as
 * `{ "code": <errorCode>, "message": <getMessage()> }`.
 */
abstract class DomainException extends RuntimeException
{
    protected string $errorCode = ErrorCode::Domain->value;

    protected int $statusCode = Response::HTTP_CONFLICT;

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
