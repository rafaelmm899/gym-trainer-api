<?php

namespace App\Actions\Session;

use App\Data\Session\LogSetData;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\DayExerciseNotInSessionException;
use App\Exceptions\Session\NonContiguousSetNumberException;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\SetLog;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

final class SetLogCreateAction
{
    public function handle(TrainingSession $session, LogSetData $data): SetLog
    {
        $this->ensureSessionOpen($session);

        $exercise = $this->resolveExercise($session, $data);

        $this->ensureSetNumberIsNext($session, $exercise, $data->set_number);

        $set = DB::transaction(fn (): SetLog => $session->sets()->create([
            'exercise_id' => $exercise->id,
            'set_number' => $data->set_number,
            'weight_kg' => $data->weight_kg,
            'reps' => $data->reps,
            'rpe' => $data->rpe,
            'note' => $data->note,
        ]));

        return $set->load('exercise');
    }

    private function ensureSessionOpen(TrainingSession $session): void
    {
        throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException);
    }

    /**
     * The set's catalogue exercise: straight from `exercise_id` for an off-plan
     * set, or via `day_exercise_id` — a prescription that must belong to this
     * session's own training day.
     */
    private function resolveExercise(TrainingSession $session, LogSetData $data): Exercise
    {
        if ($data->day_exercise_id !== null) {
            $dayExercise = DayExercise::query()
                ->with('exercise')
                ->where('uuid', $data->day_exercise_id)
                ->firstOrFail();

            $this->ensurePrescriptionInSession($session, $dayExercise);

            return $dayExercise->exercise;
        }

        return Exercise::query()->where('uuid', $data->exercise_id)->firstOrFail();
    }

    private function ensurePrescriptionInSession(TrainingSession $session, DayExercise $dayExercise): void
    {
        throw_unless(
            $dayExercise->cycle_day_id === $session->cycle_day_id,
            new DayExerciseNotInSessionException,
        );
    }

    /**
     * Sets are appended in order, one sequence per exercise: the client must
     * send exactly `(existing sets for the exercise) + 1`.
     */
    private function ensureSetNumberIsNext(TrainingSession $session, Exercise $exercise, int $setNumber): void
    {
        $expected = $session->sets()->where('exercise_id', $exercise->id)->count() + 1;

        throw_unless($setNumber === $expected, new NonContiguousSetNumberException($expected));
    }
}
