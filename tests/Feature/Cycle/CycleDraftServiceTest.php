<?php

use App\Data\Cycle\CyclePlanData;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Shared\Goal;
use App\Models\AthleteProfile;
use App\Models\Exercise;
use App\Models\Routine;
use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;
use Illuminate\Support\Facades\DB;

/**
 * Produce a validated CyclePlanData from the canned payload via the real
 * planner service (the agent is faked), so these tests exercise CycleDraftService
 * against the same DTO shape production uses.
 *
 * @param  array<string, mixed>  $overrides
 */
function planData(array $overrides = []): CyclePlanData
{
    fakeCyclePlanner($overrides);

    return app(CyclePlannerService::class)->planFirstCycle(
        AthleteProfile::factory()->create(),
        Goal::Hypertrophy,
        null,
    );
}

// TC-28
it('writes the cycle, 5 days and every prescription from a plan DTO', function () {
    $routine = Routine::factory()->create();
    $plan = planData();

    $cycle = DB::transaction(fn () => app(CycleDraftService::class)->persist($routine, $plan));

    expect($cycle->status)->toBe(CycleStatus::Draft)
        ->and($cycle->sequence_number)->toBe(1)
        ->and($cycle->generated_at)->not->toBeNull()
        ->and($cycle->split_rationale)->toBe($plan->splitRationale);

    $cycle->load('cycleDays.dayExercises');

    expect($cycle->cycleDays)->toHaveCount(5)
        ->and($cycle->cycleDays->pluck('order')->all())->toBe([1, 2, 3, 4, 5])
        ->and($cycle->cycleDays->first()->focus_muscle_groups)->toBe(['chest', 'triceps'])
        ->and($cycle->cycleDays->first()->rationale)->toBeString()->not->toBe('');

    $firstExercise = $cycle->cycleDays->first()->dayExercises->first();
    expect($firstExercise->sets)->toBe(3)
        ->and($firstExercise->rep_min)->toBe(8)
        ->and($firstExercise->rep_max)->toBe(12)
        ->and((float) $firstExercise->target_weight_kg)->toBe(40.0)
        ->and($firstExercise->rest_seconds)->toBe(90)
        ->and(Exercise::whereKey($firstExercise->exercise_id)->exists())->toBeTrue();

    $this->assertDatabaseCount('day_exercises', 25);
});

// TC-29
it('reuses catalogue rows and opens no transaction of its own', function () {
    $routine = Routine::factory()->create();
    $existing = Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'barbell-bench-press']);
    $plan = planData();

    // No wrapping transaction: rows must be there afterwards.
    $cycle = app(CycleDraftService::class)->persist($routine, $plan);

    expect(Exercise::where('slug', 'barbell-bench-press')->count())->toBe(1)
        ->and(Exercise::count())->toBe(24)
        ->and($cycle->exists)->toBeTrue();

    $this->assertDatabaseCount('cycle_days', 5);
    $this->assertDatabaseHas('day_exercises', ['exercise_id' => $existing->id]);
});
