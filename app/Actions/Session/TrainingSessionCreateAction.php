<?php

namespace App\Actions\Session;

use App\Data\Session\CreateTrainingSessionData;
use App\Enums\Session\SessionStatus;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Session\TrainingSessionOpeningService;
use Illuminate\Support\Facades\DB;

final class TrainingSessionCreateAction
{
    public function __construct(private TrainingSessionOpeningService $opening) {}

    public function handle(User $user, Routine $routine, CreateTrainingSessionData $data): TrainingSession
    {
        $day = $data->day
            ? CycleDay::query()->where('uuid', $data->day)->first()
            : null;

        $this->opening->guard($user, $routine, $day);

        $session = DB::transaction(fn (): TrainingSession => $routine->trainingSessions()->create([
            'user_id' => $user->id,
            'cycle_day_id' => $day?->id,
            'status' => SessionStatus::InProgress,
            'started_at' => now(),
        ]));

        return $session->load('cycleDay.dayExercises.exercise');
    }
}
