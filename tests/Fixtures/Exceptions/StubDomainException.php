<?php

namespace Tests\Fixtures\Exceptions;

use App\Exceptions\DomainException;

/**
 * A concrete {@see DomainException} with only the base defaults
 * (`DOMAIN_EXCEPTION` / 409) — stands in for a real domain exception until the
 * Routine / Cycle / Session domains exist.
 */
final class StubDomainException extends DomainException
{
    //
}
