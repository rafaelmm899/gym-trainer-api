<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * The submitted `set_number` is not the next one for this exercise in this
 * session. Sets are appended in order, one per exercise: the client must send
 * `(existing sets for the exercise) + 1`. HTTP 409 — the check depends on the
 * session's current set count, so it is a state conflict, not a field error.
 */
final class NonContiguousSetNumberException extends DomainException
{
    protected string $errorCode = 'NON_CONTIGUOUS_SET_NUMBER';

    public function __construct(int $expected)
    {
        parent::__construct("The next set number for this exercise is {$expected}.");
    }
}
