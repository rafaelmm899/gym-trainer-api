<?php

namespace App\Actions\Session;

use App\Enums\Recommendation\RecommendationStatus;
use App\Enums\Session\AnalysisState;
use App\Models\ExerciseRecommendation;
use App\Models\TrainingSession;
use App\Services\Recommendation\SessionAnalystService;
use Illuminate\Support\Facades\DB;

final class SessionAnalyzeAction
{
    public function __construct(private SessionAnalystService $analyst) {}

    public function handle(TrainingSession $session): void
    {
        // The AI call happens before any transaction — same reasoning as
        // RoutineCreateAction/CyclePlannerService: an external call never runs
        // inside an open transaction.
        $recommendations = $this->analyst->analyze($session);

        DB::transaction(function () use ($session, $recommendations): void {
            foreach ($recommendations as $recommendation) {
                ExerciseRecommendation::updateOrCreate(
                    [
                        'user_id' => $session->user_id,
                        'routine_id' => $session->routine_id,
                        'exercise_id' => $recommendation->exerciseId,
                    ],
                    [
                        'source_session_id' => $session->id,
                        'target_weight_kg' => $recommendation->targetWeightKg,
                        'target_sets' => $recommendation->targetSets,
                        'target_rep_min' => $recommendation->targetRepMin,
                        'target_rep_max' => $recommendation->targetRepMax,
                        'action' => $recommendation->action,
                        'explanation' => $recommendation->explanation,
                        // A fresh analysis always makes this exercise's
                        // recommendation current again, even if a prior cycle
                        // rollover had marked it applied — updateOrCreate on
                        // an existing row does not re-apply the column default.
                        'status' => RecommendationStatus::Active,
                    ],
                );
            }

            $session->update(['analysis_state' => AnalysisState::Done]);
        });
    }
}
