<?php

namespace App\Exceptions\Exercise;

use App\Exceptions\DomainException;

/**
 * A new exercise cannot enter the catalogue without a valid muscle group: the
 * planner's recovery rule depends on it. Thrown from `ExerciseCatalogService`
 * only when it would create a row; reusing an existing slug needs no hint.
 * HTTP 409 — the request is well-formed, the catalogue forbids the insert.
 */
final class ExerciseMuscleGroupRequiredException extends DomainException
{
    protected string $errorCode = 'EXERCISE_MUSCLE_GROUP_REQUIRED';

    public function __construct()
    {
        parent::__construct('A new exercise needs a valid muscle group.');
    }
}
