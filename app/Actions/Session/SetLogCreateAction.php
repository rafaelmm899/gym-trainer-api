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
        throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException);

        $exercise = $this->resolveExercise($session, $data);

        $nextNumber = $session->sets()->where('exercise_id', $exercise->id)->count() + 1;
        throw_unless($data->set_number === $nextNumber, new NonContiguousSetNumberException($nextNumber));

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

    /**
     * The set's catalogue exercise: straight from `exercise_id` for an off-plan
     * set, or via `day_exercise_id` — which must be a prescription of this
     * session's own training day (a free session has none, so any
     * `day_exercise_id` fails here).
     */
    private function resolveExercise(TrainingSession $session, LogSetData $data): Exercise
    {
        if ($data->exercise_id !== null) {
            return Exercise::query()->where('uuid', $data->exercise_id)->firstOrFail();
        }

        $dayExercise = DayExercise::query()
            ->with('exercise')
            ->where('uuid', $data->day_exercise_id)
            ->firstOrFail();

        throw_unless(
            $dayExercise->cycle_day_id === $session->cycle_day_id,
            new DayExerciseNotInSessionException,
        );

        return $dayExercise->exercise;
    }
}
