<?php

namespace App\Jobs\Cycle;

use App\Models\Routine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Placeholder for asynchronous generation of cycle N+1 ("Generar el ciclo
 * siguiente bajo demanda", backlog Order 150), which will run the planner in a
 * queued job with its own `generating` → `draft` / `failed` lifecycle and a
 * progression summary.
 *
 * The FIRST cycle is generated synchronously inside `RoutineCreateAction` (see
 * `docs/plans/generate-first-cycle-spec.md`), so nothing dispatches this job
 * yet. It stays here as the seam for that later story.
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
