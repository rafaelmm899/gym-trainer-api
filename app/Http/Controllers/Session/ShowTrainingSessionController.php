<?php

namespace App\Http\Controllers\Session;

use App\Http\Requests\Session\ShowTrainingSessionRequest;
use App\Http\Resources\Session\TrainingSessionResource;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShowTrainingSessionController
{
    public function __invoke(ShowTrainingSessionRequest $request, TrainingSession $session): TrainingSessionResource
    {
        $session->load([
            'cycleDay.dayExercises.exercise',
            'sets' => fn (HasMany $sets) => $sets->orderBy('exercise_id')->orderBy('set_number'),
            'sets.exercise',
            'recommendations' => fn (HasMany $recommendations) => $recommendations->orderBy('id'),
            'recommendations.exercise',
        ]);

        return TrainingSessionResource::make($session);
    }
}
