<?php

namespace App\Actions\Session;

use App\Enums\Session\AnalysisState;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Collection;

final class SessionAnalysisRetryAction
{
    /**
     * @return Collection<int, TrainingSession>
     */
    public function handle(): Collection
    {
        $sessions = TrainingSession::query()->where('analysis_state', AnalysisState::Failed)->get();

        $sessions->each(fn (TrainingSession $session) => SessionAnalysisJob::dispatch($session));

        return $sessions;
    }
}
