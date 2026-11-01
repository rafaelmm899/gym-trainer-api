<?php

namespace App\Actions\Session;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Exercise\ExerciseHistoryService;
use App\Services\Session\SessionSummaryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingSessionShowAction
{
    public function __construct(
        private ExerciseHistoryService $history,
        private SessionSummaryService $summaries,
    ) {}

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
        $this->summaries->attach(new Collection([$session]));

        return $session;
    }
}
