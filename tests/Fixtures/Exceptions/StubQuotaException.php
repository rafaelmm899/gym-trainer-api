<?php

namespace Tests\Fixtures\Exceptions;

use App\Exceptions\DomainException;

/**
 * A {@see DomainException} that overrides both the code and the status, to prove
 * a subclass controls its own envelope.
 */
final class StubQuotaException extends DomainException
{
    protected string $errorCode = 'STUB_QUOTA';

    protected int $statusCode = 422;
}
