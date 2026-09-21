<?php

use App\Data\Session\LogSetData;
use App\Exceptions\Cycle\CycleDayImportValidationException;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Exceptions\Session\CycleDayAlreadyCompletedException;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Cycle\CycleDayImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit coverage for docs/plans/import-training-day-xlsx-spec.md §8, Service TC-1..TC-15.
uses(TestCase::class, RefreshDatabase::class);

function importService(): CycleDayImportService
{
    return app(CycleDayImportService::class);
}

// TC-1
it('rejects a routine whose current cycle is not active', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->generating()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $file = buildXlsxUploadedFile([]);

    expect(fn () => importService()->handle($routine, $day, $file))
        ->toThrow(RoutineHasNoActiveCycleException::class);
});

// TC-2
it('rejects a day from a non-active cycle of the same routine', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $oldCycle = Cycle::factory()->completed()->for($routine)->create(['sequence_number' => 1]);
    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create();

    $file = buildXlsxUploadedFile([]);

    expect(fn () => importService()->handle($routine, $oldDay, $file))
        ->toThrow(CycleDayNotInActiveCycleException::class);
});

// TC-3
it('rejects a day that already has a completed session in the active cycle', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    TrainingSession::factory()->for($user)->for($routine)->planned($day)->completed()->create();

    $file = buildXlsxUploadedFile([]);

    expect(fn () => importService()->handle($routine, $day, $file))
        ->toThrow(CycleDayAlreadyCompletedException::class);
});

// TC-4
it('numbers filled rows contiguously per exercise', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8),
        importRow('Sentadilla', 102.5, 7),
        importRow('Sentadilla', 105, 6),
    ]);

    $logs = importService()->handle($routine, $day, $file);

    expect($logs)->toHaveCount(3)
        ->and($logs->pluck('set_number')->all())->toBe([1, 2, 3])
        ->and($logs->every(fn (LogSetData $l): bool => $l->day_exercise_id !== null))->toBeTrue();
});

// TC-5
it('numbers each exercise independently when rows are interleaved', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla', 1);
    prescribeExercise($day, 'Zancada', 2);

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8),
        importRow('Zancada', 40, 10),
        importRow('Sentadilla', 102.5, 7),
        importRow('Zancada', 42, 9),
    ]);

    $logs = importService()->handle($routine, $day, $file)->values();

    expect($logs[0]->set_number)->toBe(1)
        ->and($logs[1]->set_number)->toBe(1)
        ->and($logs[2]->set_number)->toBe(2)
        ->and($logs[3]->set_number)->toBe(2);
});

// TC-6
it('skips a row missing weight_kg', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', null, 8),
    ]);

    expect(importService()->handle($routine, $day, $file))->toHaveCount(0);
});

// TC-7
it('skips a row missing reps', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, null),
    ]);

    expect(importService()->handle($routine, $day, $file))->toHaveCount(0);
});

// TC-8
it('rejects a filled row whose exercise is not prescribed on this day', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Press Banca', 60, 8),
    ]);

    try {
        importService()->handle($routine, $day, $file);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKey('row_2.exercise');
    }
});

// TC-9
it('rejects a non-positive or non-numeric weight_kg', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', -5, 8),
    ]);

    try {
        importService()->handle($routine, $day, $file);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKey('row_2.weight_kg');
    }
});

// TC-10
it('rejects a non-positive or non-integer reps', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 0),
    ]);

    try {
        importService()->handle($routine, $day, $file);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKey('row_2.reps');
    }
});

// TC-11
it('rejects an out-of-range rpe but accepts a blank one', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $badFile = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8, 10.5),
    ]);

    try {
        importService()->handle($routine, $day, $badFile);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKey('row_2.rpe');
    }

    $goodFile = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8, null),
    ]);

    expect(importService()->handle($routine, $day, $goodFile))->toHaveCount(1);
});

// TC-12
it('collects every bad row into one exception instead of failing fast', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', -5, 8),
        importRow('Sentadilla', 100, 8),
        importRow('Sentadilla', 100, 0),
    ]);

    try {
        importService()->handle($routine, $day, $file);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKeys(['row_2.weight_kg', 'row_4.reps']);
    }
});

// TC-13
it('matches the exercise cell regardless of accent or case', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('SENTADILLA', 100, 8),
    ]);

    expect(importService()->handle($routine, $day, $file))->toHaveCount(1);
});

// TC-14
it('rejects an unreadable file', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildUnreadableXlsxUploadedFile();

    try {
        importService()->handle($routine, $day, $file);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKey('file');
    }
});

// TC-15
it('numbers a bad row by its spreadsheet row, header included', function () {
    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8),
        importRow('Sentadilla', -5, 8),
    ]);

    try {
        importService()->handle($routine, $day, $file);
        expect(false)->toBeTrue('Expected a ValidationException.');
    } catch (CycleDayImportValidationException $e) {
        expect($e->errors())->toHaveKey('row_3.weight_kg');
    }
});
