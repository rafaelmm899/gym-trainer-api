<?php

namespace App\Actions\Session;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Exercise\ExerciseHistoryService;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingSessionShowAction
{
    public function __construct(private ExerciseHistoryService $history) {}

    public function handle(User $user, TrainingSession $session): TrainingSession
    {
        $session->load([
            'cycleDay.cycle',
            'cycleDay.dayExercises.exercise',
            'sets' => fn (HasMany $sets) => $sets->orderBy('exercise_id')->orderBy('set_number'),
            'sets.exercise',
            'sets.dayExercise',
            'recommendations' => fn (HasMany $recommendations) => $recommendations->orderBy('id'),
            'recommendations.exercise',
        ]);

        $this->history->attachPrevious($user, $session);

        return $session;
    }
}
