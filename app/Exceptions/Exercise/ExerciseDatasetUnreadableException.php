<?php

namespace App\Exceptions\Exercise;

use App\Exceptions\DomainException;

/**
 * The dataset file or download could not be read as a JSON list of records, so
 * nothing is imported. Thrown by `ExerciseDatasetService` and reported by
 * `exercises:import`.
 */
final class ExerciseDatasetUnreadableException extends DomainException
{
    protected string $errorCode = 'EXERCISE_DATASET_UNREADABLE';
}
