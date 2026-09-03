<?php

namespace App\Actions\Session;

use App\Data\Session\LogSetData;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Services\Session\SetLoggingService;
use Illuminate\Support\Facades\DB;

final class SetLogCreateAction
{
    public function __construct(private SetLoggingService $sets) {}

    public function handle(TrainingSession $session, LogSetData $data): SetLog
    {
        $this->sets->guardOpen($session);
        $exercise = $this->sets->resolveExercise($session, $data);
        $this->sets->guardContiguousSetNumber($session, $exercise, $data->set_number);

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
}
