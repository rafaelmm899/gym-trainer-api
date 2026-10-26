<?php

namespace App\Jobs\Cycle;

use App\Actions\Cycle\CycleFinalizeAction;
use App\Enums\Cycle\CycleStatus;
use App\Models\Cycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CycleFinalizeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public Cycle $cycle) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(CycleFinalizeAction $action): void
    {
        $action->handle($this->cycle);
    }

    public function failed(Throwable $exception): void
    {
        $this->cycle->update(['status' => CycleStatus::Failed]);
    }
}
