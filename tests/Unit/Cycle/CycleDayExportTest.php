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

// Unit coverage for docs/plans/export-training-day-csv-spec.md §8, TC-14..TC-27.
uses(TestCase::class, RefreshDatabase::class);

function exportFor(Routine $routine, CycleDay $day): CycleDayExport
{
    return new CycleDayExport($routine, $day, app(RecommendationCatalogService::class));
}

/**
 * @return list<array<int, mixed>>
 */
function exportRows(Routine $routine, CycleDay $day): array
{
    return exportFor($routine, $day)->array();
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
it('builds a CycleDayExport whose header row is exact', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create();

    $export = exportFor($routine, $day);

    expect($export)->toBeInstanceOf(CycleDayExport::class)
        ->and($export->array())->toContain([
            'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
            'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
        ]);
});

// TC-15
it('emits one row per prescribed set with a contiguous set_number', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'X']))->create(['sets' => 3]);

    $data = exportDataRows(exportRows($routine, $day));

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

    $rows = exportRows($routine, $day);
    $data = exportDataRows($rows);
    $prescriptions = array_values(array_filter(exportCommentLines($rows), fn (string $l): bool => str_contains($l, 'prescripcion:')));

    expect($data[0][3])->toBe(5)
        ->and($data[1][3])->toBe('8-12')
        ->and($prescriptions[0])->toContain('x5')
        ->and($prescriptions[1])->toContain('x8-12');
});

// TC-17
it('leaves prescribed weight and rpe blank and trims the # fragment when null', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create([
        'target_weight_kg' => null,
        'target_rpe' => null,
        'rest_seconds' => 90,
        'sets' => 2,
    ]);

    $rows = exportRows($routine, $day);
    $data = exportDataRows($rows);
    $prescription = collect(exportCommentLines($rows))->first(fn (string $l): bool => str_contains($l, 'prescripcion:'));

    expect($data[0][2])->toBeNull()
        ->and($data[0][4])->toBeNull()
        ->and($prescription)->toContain('descanso 90s')
        ->and($prescription)->not->toContain('@')
        ->and($prescription)->not->toContain('RPE');
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

    $data = exportDataRows(exportRows($routine, $day));

    expect($data[0][6])->toBe(80.0)->and($data[0][7])->toBe('hold')
        ->and($data[1][6])->toBeNull()->and($data[1][7])->toBeNull()
        ->and($data[2][6])->toBeNull()->and($data[2][7])->toBeNull();
});

// TC-19
it('includes the split rationale line only when the cycle has one', function () {
    [$withRationale, $day1] = exportActiveDay(['split_rationale' => "Empuje\nprimero"]);
    DayExercise::factory()->for($day1)->create();

    [$withoutRationale, $day2] = exportActiveDay(['split_rationale' => null]);
    DayExercise::factory()->for($day2)->create();

    expect(exportCommentLines(exportRows($withRationale, $day1)))
        ->toContain('# racional del split: Empuje primero')
        ->and(implode("\n", exportCommentLines(exportRows($withoutRationale, $day2))))
        ->not->toContain('racional del split');
});

// TC-20
it('collapses newlines in rationale and explanation to single spaces', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);

    DayExercise::factory()->for($day)->for($exercise)->create(['rationale' => "line one\nline two", 'sets' => 1]);
    ExerciseRecommendation::factory()->for($user)->for($routine)->for($exercise)->create([
        'status' => RecommendationStatus::Active,
        'explanation' => "a\n\nb",
        'action' => RecommendationAction::Hold,
    ]);

    $line = collect(exportCommentLines(exportRows($routine, $day)))
        ->first(fn (string $l): bool => str_starts_with($l, '# Sentadilla'));

    expect($line)->toContain('Racional: line one line two')
        ->and($line)->toContain('— a b')
        ->and($line)->not->toContain("\n");
});

// TC-21
it('slugs the filename to .xlsx and falls back for a value with no slug characters', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create(['name' => 'Volumen Invierno ñ']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $day = CycleDay::factory()->for($cycle)->create(['label' => '!!!', 'order' => 4]);

    expect(exportFor($routine, $day)->filename)
        ->toBe('volumen-invierno-n-ciclo-2-dia-4-sin-nombre.xlsx');
});

// TC-22
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

// TC-23
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

// TC-24
it('gives the same exercise prescribed twice a continuous set_number', function () {
    [$routine, $day] = exportActiveDay();
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['order' => 1, 'sets' => 4, 'target_weight_kg' => 100]);
    DayExercise::factory()->for($day)->for($squat)->create(['order' => 2, 'sets' => 3, 'target_weight_kg' => 80]);

    $data = exportDataRows(exportRows($routine, $day));

    expect($data)->toHaveCount(7)
        ->and(array_column($data, 0))->toBe(array_fill(0, 7, 'Sentadilla'))
        ->and(array_column($data, 1))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(array_column($data, 2))->toBe([100.0, 100.0, 100.0, 100.0, 80.0, 80.0, 80.0]);
});

// TC-25
it('orders rows by day_exercise.order', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))->create(['order' => 2, 'sets' => 1]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'A']))->create(['order' => 1, 'sets' => 1]);

    expect(array_column(exportDataRows(exportRows($routine, $day)), 0))->toBe(['A', 'B']);
});

// TC-26
it('scopes the workbook to the requested day only', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();

    $other = CycleDay::factory()->for($cycle)->create(['order' => 1]);
    DayExercise::factory()->for($other)->for(Exercise::factory()->create(['name' => 'Press banca']))->create();

    $target = CycleDay::factory()->for($cycle)->create(['order' => 2]);
    DayExercise::factory()->for($target)->for(Exercise::factory()->create(['name' => 'Sentadilla']))->create(['sets' => 1]);

    $rows = exportRows($routine, $target);
    $flat = implode("\n", exportCommentLines($rows)).implode(',', array_column(exportDataRows($rows), 0));

    expect($flat)->toContain('Sentadilla')->not->toContain('Press banca');
});

// TC-27
it('exports a day with zero exercises: prelude + header only', function () {
    [$routine, $day] = exportActiveDay();

    $rows = exportRows($routine, $day);

    expect(exportDataRows($rows))->toBeEmpty()
        ->and(exportCommentLines($rows)[0])->toStartWith('# rutina:');
});
