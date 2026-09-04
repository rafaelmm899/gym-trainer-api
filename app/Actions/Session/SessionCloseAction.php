<?php

namespace App\Actions\Session;

use App\Data\Session\CompleteSessionData;
use App\Enums\Session\SessionStatus;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\TrainingSession;
use App\Services\Session\SessionCompletionService;
use Illuminate\Support\Facades\DB;

final class SessionCloseAction
{
    public function __construct(private SessionCompletionService $completion) {}

    public function handle(TrainingSession $session, CompleteSessionData $data): TrainingSession
    {
        return DB::transaction(function () use ($session, $data): TrainingSession {
            $this->completion->guard($session);

            $session->update([
                'status' => SessionStatus::Completed,
                'completed_at' => now(),
                'note' => $data->note,
                'perceived_effort' => $data->perceived_effort,
            ]);

            SessionAnalysisJob::dispatch($session);

            return $session->load('cycleDay');
        });
    }
}
