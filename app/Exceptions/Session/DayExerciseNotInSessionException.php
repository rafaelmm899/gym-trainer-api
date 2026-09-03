<?php

namespace App\Exceptions\Session;

use App\Exceptions\DomainException;

/**
 * The `day_exercise_id` in the request is a real prescription row, but it does
 * not belong to this session's training day — a different cycle day, an older
 * cycle, another user's routine, or the session is free (no planned day at
 * all). Log the set with `exercise_id` instead. HTTP 409.
 */
final class DayExerciseNotInSessionException extends DomainException
{
    protected string $errorCode = 'DAY_EXERCISE_NOT_IN_SESSION';

    public function __construct()
    {
        parent::__construct("That prescribed exercise does not belong to this session's training day.");
    }
}
