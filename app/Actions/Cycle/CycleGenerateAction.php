<?php

namespace App\Actions\Cycle;

use App\Actions\Routine\RoutineCreateAction;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Cycle\DayProgressionState;
use App\Enums\Routine\RoutineStatus;
use App\Exceptions\Cycle\CycleGenerationInProgressException;
use App\Exceptions\Cycle\RoutineNotActiveException;
use App\Jobs\Cycle\CycleDayProgressionJob;
use App\Jobs\Cycle\CycleFinalizeJob;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;
use App\Services\Cycle\ProgressionSummaryService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Starts generating cycle N+1 for a routine's active cycle — same days and
 * exercises, progressed — and returns right away: the new cycle is created
 * `generating` as a copy of the outgoing one, then one queued job per trained
 * day progresses it (a minute apart, so the AI provider's rate limit holds) and
 * a last job activates it and rolls the outgoing cycle over
 * ({@see CycleFinalizeAction}). The outgoing cycle stays `active`, and
 * trainable, meanwhile. A previous attempt left `failed` is discarded and
 * redone.
 *
 * Same guard-then-write shape as {@see RoutineCreateAction}; the jobs are
 * dispatched after the transaction.
 */
final class CycleGenerateAction
{
    private const SECONDS_BETWEEN_DAYS = 60;

    public function __construct(
        private CyclePlannerService $planner,
        private CycleDraftService $draft,
        private ProgressionSummaryService $progression,
    ) {}

    public function handle(Routine $routine): Cycle
    {
        $outgoingCycle = $this->ensureCanGenerate($routine);

        $performed = $this->planner->performedSlots($outgoingCycle, $this->progression->summarize($routine, $outgoingCycle));
        $trainedDayOrders = $performed->keys()->map(fn (string $key): int => (int) explode('.', $key)[0])->unique();

        $newCycle = DB::transaction(function () use ($routine, $outgoingCycle, $trainedDayOrders): Cycle {
            $routine->cycles()->where('status', CycleStatus::Failed)->delete();

            $newCycle = $routine->cycles()->create([
                'sequence_number' => $outgoingCycle->sequence_number + 1,
                'status' => CycleStatus::Generating,
                'generated_at' => now(),
            ]);

            $this->draft->persistDays($newCycle, $this->planner->cloneDays($outgoingCycle));

            $newCycle->cycleDays()
                ->whereIn('order', $trainedDayOrders)
                ->update(['progression_state' => DayProgressionState::Pending]);

            return $newCycle;
        });

        $dayJobs = $newCycle->cycleDays()
            ->where('progression_state', DayProgressionState::Pending)
            ->orderBy('order')
            ->get()
            ->values()
            ->map(fn (CycleDay $day, int $index): CycleDayProgressionJob => (new CycleDayProgressionJob($day))
                ->delay($index === 0 ? null : self::SECONDS_BETWEEN_DAYS));

        Bus::chain([...$dayJobs, new CycleFinalizeJob($newCycle)])->dispatch();

        return $newCycle->load('cycleDays.dayExercises.exercise');
    }

    /**
     * A cycle can only be generated for the caller's active routine, and not
     * while its next cycle is already being generated. Returns the routine's
     * active cycle so the caller can compute the next `sequence_number` and
     * clone it without a second query. `sole()` is deliberate: by this point the routine is confirmed
     * active, so zero or more than one active cycle is an invariant
     * violation, not a case to handle gracefully.
     */
    private function ensureCanGenerate(Routine $routine): Cycle
    {
        throw_unless($routine->status === RoutineStatus::Active, new RoutineNotActiveException);
        throw_if(
            $routine->cycles()->where('status', CycleStatus::Generating)->exists(),
            new CycleGenerationInProgressException,
        );

        return Cycle::query()
            ->where('routine_id', $routine->id)
            ->where('status', CycleStatus::Active)
            ->with('cycleDays.dayExercises.exercise')
            ->sole();
    }
}
