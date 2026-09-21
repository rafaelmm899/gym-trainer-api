<?php

namespace App\Console\Commands\Session;

use App\Actions\Session\SessionAnalysisRetryAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RetryFailedSessionAnalysesCommand extends Command
{
    protected $signature = 'sessions:retry-failed-analysis';

    protected $description = 'Re-dispatch SessionAnalysisJob for every training session whose AI analysis failed.';

    public function handle(SessionAnalysisRetryAction $action): int
    {
        $sessions = $action->handle();

        Log::info('sessions:retry-failed-analysis: retried failed session analyses.', [
            'found' => $sessions->count(),
            'requeued' => $sessions->count(),
        ]);

        return self::SUCCESS;
    }
}
