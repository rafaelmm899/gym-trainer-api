<?php

namespace App\Services\Session;

use App\Data\Session\LogSetData;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\DayExerciseNotInSessionException;
use App\Exceptions\Session\NonContiguousSetNumberException;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\TrainingSession;

/**
 * The business invariants of writing a set: the session must be open, a
 * `day_exercise_id` must belong to that session's training day, and
 * `set_number` must be the next one for the exercise in that session.
 */
final class SetLoggingService
{
    public function guardOpen(TrainingSession $session): void
    {
        throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException);
    }

    /**
     * Resolve the catalogue exercise for a set: from the prescription when
     * `day_exercise_id` is given (and only if that prescription is part of this
     * session's day), otherwise the `exercise_id` straight from the catalogue.
     */
    public function resolveExercise(TrainingSession $session, LogSetData $data): Exercise
    {
        if ($data->day_exercise_id !== null) {
            $dayExercise = DayExercise::query()
                ->with('exercise')
                ->where('uuid', $data->day_exercise_id)
                ->firstOrFail();

            throw_unless(
                $session->cycle_day_id !== null && $dayExercise->cycle_day_id === $session->cycle_day_id,
                new DayExerciseNotInSessionException,
            );

            return $dayExercise->exercise;
        }

        return Exercise::query()->where('uuid', $data->exercise_id)->firstOrFail();
    }

    public function guardContiguousSetNumber(TrainingSession $session, Exercise $exercise, int $setNumber): void
    {
        $expected = $session->sets()->where('exercise_id', $exercise->id)->count() + 1;

        throw_unless($setNumber === $expected, new NonContiguousSetNumberException($expected));
    }
}
