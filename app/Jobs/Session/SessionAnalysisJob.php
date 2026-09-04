<?php

namespace App\Jobs\Session;

use App\Actions\Session\SessionAnalyzeAction;
use App\Enums\Session\AnalysisState;
use App\Models\TrainingSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the AI analysis that produces exercise recommendations when a session
 * is completed ("Recibir recomendaciones al cerrar el día", backlog Order 130):
 * moves `analysis_state` `pending` → `processing`, delegates the actual work to
 * {@see SessionAnalyzeAction}, which moves it to `done` once every recommendation
 * is persisted. Dispatched by `SessionCloseAction`, after (not inside) the
 * transaction that closes the session — see that class for why.
 *
 * `$tries` / `backoff()` absorb a transient provider hiccup under a real queue
 * worker; `failed()` is the only place `analysis_state` becomes `failed`,
 * reached once retries are exhausted (immediately, under the `sync` driver
 * the test suite uses, which does not retry in-process).
 */
final class SessionAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public TrainingSession $session) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SessionAnalyzeAction $action): void
    {
        $this->session->update(['analysis_state' => AnalysisState::Processing]);

        $action->handle($this->session);
    }

    public function failed(Throwable $exception): void
    {
        $this->session->update(['analysis_state' => AnalysisState::Failed]);
    }
}
