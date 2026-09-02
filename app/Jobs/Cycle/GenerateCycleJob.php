<?php

namespace App\Jobs\Cycle;

use App\Models\Routine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued from routine creation (and, later, on-demand for cycle N+1).
 *
 * Placeholder: the AI cycle planner, the `cycles` / `cycle_days` /
 * `day_exercises` schema and the `generating` → `draft` / `failed` lifecycle
 * ship with the "Recibir el primer ciclo apenas creo una rutina" story. This
 * class exists so routine creation has a real job to dispatch and assert on.
 */
final class GenerateCycleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Routine $routine) {}

    public function handle(): void
    {
        //
    }
}
