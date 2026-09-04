<?php

namespace App\Jobs\Session;

use App\Models\TrainingSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Placeholder for the AI analysis that runs when a session is completed
 * ("Recibir recomendaciones al cerrar el día", backlog Order 130): compares
 * prescribed vs. executed sets and produces an `ExerciseRecommendation` per
 * exercise trained. Dispatched by `SessionCloseAction` on every completion;
 * `analysis_state` stays `pending` until that story implements the agent and
 * the `pending` → `processing` → `done` / `failed` lifecycle.
 */
final class SessionAnalysisJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public TrainingSession $session) {}

    public function handle(): void
    {
        //
    }
}
