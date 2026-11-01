<?php

namespace App\Jobs\Cycle;

use App\Actions\Cycle\CycleDayProgressAction;
use App\Enums\Cycle\CycleStatus;
use App\Models\CycleDay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Progresses one day of a cycle N+1. Chained one after another with a pause
 * between them (so the AI provider's rate limit is not hit); an AI failure is
 * retried after a minute, and the last attempt falls back instead of failing,
 * which keeps the chain going.
 */
final class CycleDayProgressionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public CycleDay $day) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 60];
    }

    public function handle(CycleDayProgressAction $action): void
    {
        $action->handle($this->day, $this->attempts() >= $this->tries);
    }

    public function failed(Throwable $exception): void
    {
        $this->day->cycle()->update(['status' => CycleStatus::Failed]);
    }
}
