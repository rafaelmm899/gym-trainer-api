<?php

namespace App\Services\Cycle;

use App\Actions\Routine\RoutineCreateAction;
use App\Data\Cycle\CyclePlanData;
use App\Data\Cycle\CyclePlanDayData;
use App\Enums\Cycle\CycleStatus;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Services\Exercise\ExerciseCatalogService;

/**
 * Writes a validated {@see CyclePlanData} to the database as {@see CycleDay}
 * rows and one `day_exercises` row per prescription (each exercise resolved
 * through the catalogue). The first cycle is born `active` — there is no
 * "activate a cycle" step in the MVP.
 *
 * Opens no transaction — the caller ({@see RoutineCreateAction} for the first
 * cycle, `App\Actions\Cycle\CycleGenerateAction` for cycle N+1) wraps this
 * call together with its own cycle-row write so the whole create/rollover is
 * atomic.
 */
final class CycleDraftService
{
    public function __construct(private readonly ExerciseCatalogService $catalog) {}

    /**
     * First-cycle path only: creates the `cycles` row itself
     * (`sequence_number = 1`, born `active`) and persists its days. Cycle N+1
     * creates its own `cycles` row (a different `sequence_number`, computed
     * from the outgoing cycle) and calls {@see self::persistDays()} directly.
     */
    public function persist(Routine $routine, CyclePlanData $plan): Cycle
    {
        $cycle = $routine->cycles()->create([
            'sequence_number' => 1,
            'status' => CycleStatus::Active,
            'split_rationale' => $plan->splitRationale,
            'generated_at' => now(),
            'activated_at' => now(),
        ]);

        $this->persistDays($cycle, $plan);

        return $cycle;
    }

    /**
     * Writes the plan's days and exercises onto an already-persisted
     * `cycles` row, without touching that row's own columns (`status`,
     * `sequence_number`, …) — the caller sets those separately.
     */
    public function persistDays(Cycle $cycle, CyclePlanData $plan): void
    {
        foreach ($plan->days as $index => $day) {
            $this->persistDay($cycle, $day, $index + 1);
        }
    }

    private function persistDay(Cycle $cycle, CyclePlanDayData $day, int $order): void
    {
        /** @var CycleDay $cycleDay */
        $cycleDay = $cycle->cycleDays()->create([
            'order' => $order,
            'label' => $day->label,
            'focus_muscle_groups' => $day->focusMuscleGroups,
            'rationale' => $day->rationale,
        ]);

        foreach ($day->exercises as $index => $exercise) {
            $catalogued = $this->catalog->resolve($exercise->name, $exercise->primaryMuscleGroup);

            $cycleDay->dayExercises()->create([
                'exercise_id' => $catalogued->id,
                'order' => $index + 1,
                'sets' => $exercise->sets,
                'rep_min' => $exercise->repMin,
                'rep_max' => $exercise->repMax,
                'target_weight_kg' => $exercise->targetWeightKg,
                'target_rpe' => $exercise->targetRpe,
                'rest_seconds' => $exercise->restSeconds,
                'rationale' => $exercise->rationale,
            ]);
        }
    }
}
