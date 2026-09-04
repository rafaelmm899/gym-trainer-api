<?php

namespace App\Actions\Session;

use App\Data\Session\CompleteSessionData;
use App\Enums\Session\SessionStatus;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\TrainingSession;
use App\Services\Session\SessionCompletionService;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SessionCloseAction
{
    public function __construct(private SessionCompletionService $completion) {}

    public function handle(TrainingSession $session, CompleteSessionData $data): TrainingSession
    {
        $session = DB::transaction(function () use ($session, $data): TrainingSession {
            $this->completion->guard($session);

            $session->update([
                'status' => SessionStatus::Completed,
                'completed_at' => now(),
                'note' => $data->note,
                'perceived_effort' => $data->perceived_effort,
            ]);

            return $session->load('cycleDay');
        });

        // Dispatched *after* the transaction commits, not as its last line:
        // under the `sync` queue driver (the test suite's QUEUE_CONNECTION),
        // dispatch() runs the job inline. Doing that from inside the
        // transaction above would let an analysis failure roll back the
        // session close itself. Doing it here means the session row is
        // already durably committed by the time the job can possibly throw.
        try {
            SessionAnalysisJob::dispatch($session);
        } catch (Throwable) {
            // Only the `sync` driver can reach here (a real queue connection's
            // dispatch() just inserts a `jobs` row and returns — it never runs
            // `handle()` inline, so it never throws for a provider/analysis
            // failure). `SyncQueue` already called the job's `failed()` hook
            // (which moved `analysis_state` to `failed`) before rethrowing;
            // there is nothing left to do here except make sure the exception
            // does not fail this request — the session is already completed.
        }

        return $session;
    }
}
