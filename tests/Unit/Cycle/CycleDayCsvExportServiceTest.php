<?php

use App\Enums\Recommendation\RecommendationAction;
use App\Enums\Recommendation\RecommendationStatus;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use App\Services\Cycle\CycleDayCsvExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit coverage for docs/plans/export-training-day-csv-spec.md §8, TC-26..TC-36.
uses(TestCase::class, RefreshDatabase::class);

function exportService(): CycleDayCsvExportService
{
    return app(CycleDayCsvExportService::class);
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

// TC-26
it('builds the exact header row as the first non-comment line', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create();

    $contents = exportService()->handle($routine, $day)['contents'];

    $firstNonComment = collect(explode("\n", $contents))
        ->first(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'));

    expect($firstNonComment)->toBe(
        'exercise,set_number,prescribed_weight_kg,prescribed_reps,prescribed_rpe,rest_seconds,recommended_weight_kg,recommended_action,weight_kg,reps,rpe,note'
    );
});

// TC-27
it('emits one row per prescribed set with a contiguous set_number', function () {
    [$routine, $day] = exportActiveDay();
    $exercise = Exercise::factory()->create(['name' => 'X']);
    DayExercise::factory()->for($day)->for($exercise)->create(['sets' => 3]);

    $rows = csvDataRows(exportService()->handle($routine, $day)['contents']);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 0))->toBe(['X', 'X', 'X'])
        ->and(array_column($rows, 1))->toBe(['1', '2', '3']);
});

// TC-28
it('renders prescribed_reps as a single value or a range', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'A']))
        ->create(['order' => 1, 'sets' => 1, 'rep_min' => 5, 'rep_max' => 5]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))
        ->create(['order' => 2, 'sets' => 1, 'rep_min' => 8, 'rep_max' => 12]);

    $contents = exportService()->handle($routine, $day)['contents'];
    $rows = csvDataRows($contents);

    expect($rows[0][3])->toBe('5')
        ->and($rows[1][3])->toBe('8-12')
        ->and($contents)->toContain('x5')
        ->and($contents)->toContain('x8-12');
});

// TC-29
it('leaves prescribed weight and rpe empty and trims the # fragment when null', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create([
        'target_weight_kg' => null,
        'target_rpe' => null,
        'rest_seconds' => 90,
        'sets' => 2,
    ]);

    $contents = exportService()->handle($routine, $day)['contents'];
    $rows = csvDataRows($contents);

    expect($rows[0][2])->toBe('')
        ->and($rows[0][4])->toBe('')
        ->and($contents)->toContain('descanso 90s')
        ->and($contents)->not->toContain('@ ')
        ->and($contents)->not->toContain('RPE');
});

// TC-30
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

    $rows = csvDataRows(exportService()->handle($routine, $day)['contents']);

    expect($rows[0][6])->toBe('80')->and($rows[0][7])->toBe('hold')
        ->and($rows[1][6])->toBe('')->and($rows[1][7])->toBe('')
        ->and($rows[2][6])->toBe('')->and($rows[2][7])->toBe('');
});

// TC-31
it('includes the split rationale line only when the cycle has one', function () {
    [$withRationale, $day1] = exportActiveDay(['split_rationale' => "Empuje\nprimero"]);
    DayExercise::factory()->for($day1)->create();

    [$withoutRationale, $day2] = exportActiveDay(['split_rationale' => null]);
    DayExercise::factory()->for($day2)->create();

    expect(exportService()->handle($withRationale, $day1)['contents'])
        ->toContain('# racional del split: Empuje primero')
        ->and(exportService()->handle($withoutRationale, $day2)['contents'])
        ->not->toContain('# racional del split:');
});

// TC-32
it('collapses newlines in rationale and explanation to single spaces', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);

    DayExercise::factory()->for($day)->for($exercise)->create([
        'rationale' => "line one\nline two",
        'sets' => 1,
    ]);
    ExerciseRecommendation::factory()->for($user)->for($routine)->for($exercise)->create([
        'status' => RecommendationStatus::Active,
        'explanation' => "a\n\nb",
        'action' => RecommendationAction::Hold,
    ]);

    $line = collect(explode("\n", exportService()->handle($routine, $day)['contents']))
        ->first(fn (string $l): bool => str_starts_with($l, '# Sentadilla'));

    expect($line)->toContain('Racional: line one line two')
        ->and($line)->toContain('— a b')
        ->and($line)->not->toContain("\n");
});

// TC-33
it('slugs the filename and falls back for a value with no slug characters', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create(['name' => 'Volumen Invierno ñ']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $day = CycleDay::factory()->for($cycle)->create(['label' => '!!!', 'order' => 4]);

    expect(exportService()->handle($routine, $day)['filename'])
        ->toBe('volumen-invierno-n-ciclo-2-dia-4-sin-nombre.csv');
});

// TC-34
it('throws a 422 domain exception when the routine has no active cycle', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $cycle = Cycle::factory()->generating()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    try {
        exportService()->handle($routine, $day);
        $this->fail('Expected RoutineHasNoActiveCycleException');
    } catch (RoutineHasNoActiveCycleException $e) {
        expect($e->statusCode())->toBe(422)
            ->and($e->errorCode())->toBe('ROUTINE_HAS_NO_ACTIVE_CYCLE');
    }
});

// TC-35
it('throws a 422 domain exception when the day is not in the active cycle', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $oldCycle = Cycle::factory()->completed()->for($routine)->create(['sequence_number' => 1]);
    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create();

    try {
        exportService()->handle($routine, $oldDay);
        $this->fail('Expected CycleDayNotInActiveCycleException');
    } catch (CycleDayNotInActiveCycleException $e) {
        expect($e->statusCode())->toBe(422)
            ->and($e->errorCode())->toBe('CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
    }
});

// TC-36
it('returns a filename and the full csv contents', function () {
    [$routine, $day] = exportActiveDay();
    DayExercise::factory()->for($day)->create();

    $result = exportService()->handle($routine, $day);

    expect(array_keys($result))->toBe(['filename', 'contents'])
        ->and($result['filename'])->toMatch('/^[a-z0-9-]+-ciclo-\d+-dia-\d+-[a-z0-9-]+\.csv$/')
        ->and($result['contents'])->toStartWith('# rutina:')
        ->and($result['contents'])->toContain('exercise,set_number,prescribed_weight_kg');
});
