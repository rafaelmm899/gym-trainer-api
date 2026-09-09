<?php

use App\Enums\Recommendation\RecommendationAction;
use App\Enums\Recommendation\RecommendationStatus;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Exports\Cycle\CycleDayExport;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use App\Services\Recommendation\RecommendationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit coverage for docs/plans/export-training-day-csv-spec.md §8, TC-14..TC-24.
uses(TestCase::class, RefreshDatabase::class);

function exportFor(Routine $routine, CycleDay $day): CycleDayExport
{
    return new CycleDayExport($routine, $day, app(RecommendationCatalogService::class));
}

/**
 * A routine owned by a fresh user with one `active` cycle and one day.
 *
 * @param  array<string, mixed>  $cycleAttributes
 * @param  array<string, mixed>  $dayAttributes
 * @return array{0: Routine, 1: CycleDay}
 */
function exportActiveDay(array $cycleAttributes = [], array $dayAttributes = []): array
{
    $routine = Routine::factory()->for(User::factory())->create();
    $cycle = Cycle::factory()->active()->for($routine)->create($cycleAttributes);
    $day = CycleDay::factory()->for($cycle)->create($dayAttributes);

    return [$routine, $day];
}

// TC-14
it('exposes the exact column header', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create();

    expect(exportFor($routine, $day)->headings())->toBe([
        'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
        'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
    ]);
});

// TC-15
it('emits one data row per prescribed set with a contiguous set_number', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'X']))->create(['sets' => 3]);

    $data = exportFor($routine, $day)->array();

    expect($data)->toHaveCount(3)
        ->and(array_column($data, 0))->toBe(['X', 'X', 'X'])
        ->and(array_column($data, 1))->toBe([1, 2, 3]);
});

// TC-16
it('renders prescribed_reps as an integer or a range string', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'A']))
        ->create(['order' => 1, 'sets' => 1, 'rep_min' => 5, 'rep_max' => 5]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))
        ->create(['order' => 2, 'sets' => 1, 'rep_min' => 8, 'rep_max' => 12]);

    $data = exportFor($routine, $day)->array();

    expect($data[0][3])->toBe(5)
        ->and($data[1][3])->toBe('8-12');
});

// TC-17
it('leaves prescribed weight and rpe blank when null', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create([
        'target_weight_kg' => null,
        'target_rpe' => null,
        'rest_seconds' => 90,
        'sets' => 2,
    ]);

    $row = exportFor($routine, $day)->array()[0];

    expect($row[2])->toBeNull()
        ->and($row[4])->toBeNull()
        ->and($row[5])->toBe(90);
});

// TC-18
it('fills the recommended columns only from an active recommendation', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $withActive = Exercise::factory()->create(['name' => 'A']);
    $withApplied = Exercise::factory()->create(['name' => 'B']);
    $withNone = Exercise::factory()->create(['name' => 'C']);

    DayExercise::factory()->for($day)->for($withActive)->create(['order' => 1, 'sets' => 1]);
    DayExercise::factory()->for($day)->for($withApplied)->create(['order' => 2, 'sets' => 1]);
    DayExercise::factory()->for($day)->for($withNone)->create(['order' => 3, 'sets' => 1]);

    ExerciseRecommendation::factory()->for($user)->for($routine)->for($withActive)->create([
        'status' => RecommendationStatus::Active,
        'target_weight_kg' => 80,
        'action' => RecommendationAction::Hold,
    ]);
    ExerciseRecommendation::factory()->for($user)->for($routine)->for($withApplied)->create([
        'status' => RecommendationStatus::Applied,
    ]);

    $data = exportFor($routine, $day)->array();

    expect($data[0][6])->toBe(80.0)->and($data[0][7])->toBe('hold')
        ->and($data[1][6])->toBeNull()->and($data[1][7])->toBeNull()
        ->and($data[2][6])->toBeNull()->and($data[2][7])->toBeNull();
});

// TC-19
it('leaves the four actuals columns blank on every row', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create(['sets' => 2]);

    $data = exportFor($routine, $day)->array();

    expect($data)->toHaveCount(2)
        ->and(collect($data)->every(fn (array $r): bool => $r[8] === null && $r[9] === null && $r[10] === null && $r[11] === null))
        ->toBeTrue();
});

// TC-20
it('slugs the filename to .xlsx and falls back for a value with no slug characters', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create(['name' => 'Volumen Invierno ñ']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $day = CycleDay::factory()->for($cycle)->create(['label' => '!!!', 'order' => 4]);

    expect(exportFor($routine, $day)->filename)
        ->toBe('volumen-invierno-n-ciclo-2-dia-4-sin-nombre.xlsx');
});

// TC-21
it('throws a 422 domain exception when the routine has no active cycle', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $cycle = Cycle::factory()->generating()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    try {
        exportFor($routine, $day);
        $this->fail('Expected RoutineHasNoActiveCycleException');
    } catch (RoutineHasNoActiveCycleException $e) {
        expect($e->statusCode())->toBe(422)
            ->and($e->errorCode())->toBe('ROUTINE_HAS_NO_ACTIVE_CYCLE');
    }
});

// TC-22
it('throws a 422 domain exception when the day is not in the active cycle', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $oldCycle = Cycle::factory()->completed()->for($routine)->create(['sequence_number' => 1]);
    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create();

    try {
        exportFor($routine, $oldDay);
        $this->fail('Expected CycleDayNotInActiveCycleException');
    } catch (CycleDayNotInActiveCycleException $e) {
        expect($e->statusCode())->toBe(422)
            ->and($e->errorCode())->toBe('CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
    }
});

// TC-23
it('numbers sets continuously per exercise and orders rows by day_exercise.order', function () {
    [$routine, $day] = exportActiveDay();
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['order' => 1, 'sets' => 4, 'target_weight_kg' => 100]);
    DayExercise::factory()->for($day)->for($squat)->create(['order' => 2, 'sets' => 3, 'target_weight_kg' => 80]);

    $data = exportFor($routine, $day)->array();

    expect($data)->toHaveCount(7)
        ->and(array_column($data, 0))->toBe(array_fill(0, 7, 'Sentadilla'))
        ->and(array_column($data, 1))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(array_column($data, 2))->toBe([100.0, 100.0, 100.0, 100.0, 80.0, 80.0, 80.0]);
});

// TC-24
it('scopes the workbook to the requested day only', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();

    $other = CycleDay::factory()->for($cycle)->create(['order' => 1]);
    DayExercise::factory()->for($other)->for(Exercise::factory()->create(['name' => 'Press banca']))->create();

    $target = CycleDay::factory()->for($cycle)->create(['order' => 2]);
    DayExercise::factory()->for($target)->for(Exercise::factory()->create(['name' => 'Sentadilla']))->create(['sets' => 1]);

    expect(array_column(exportFor($routine, $target)->array(), 0))->toBe(['Sentadilla']);
});
