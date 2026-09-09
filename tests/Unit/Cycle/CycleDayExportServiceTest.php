<?php

use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Exports\Cycle\CycleDayExport;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\User;
use App\Services\Cycle\CycleDayExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit coverage for docs/plans/export-training-day-csv-spec.md §8, Service TC-1..TC-5.
uses(TestCase::class, RefreshDatabase::class);

function exportService(): CycleDayExportService
{
    return app(CycleDayExportService::class);
}

// TC-1
it('hands the export the day-exercises and a keyed recommendations map', function () {
    $routine = Routine::factory()->for(User::factory())->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();
    DayExercise::factory()->count(2)->for($day)
        ->sequence(fn ($s) => ['order' => $s->index + 1])
        ->create();

    $export = exportService()->handle($routine, $day);

    expect($export)->toBeInstanceOf(CycleDayExport::class)
        ->and($export->collection())->toHaveCount(2);
});

// TC-2
it('names the file <routine>-ciclo-<n>-dia-<order>-<label>.xlsx', function () {
    $routine = Routine::factory()->for(User::factory())->create(['name' => 'Volumen Invierno ñ']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $day = CycleDay::factory()->for($cycle)->create(['label' => 'Piernas', 'order' => 4]);

    expect(exportService()->handle($routine, $day)->filename)
        ->toBe('volumen-invierno-n-ciclo-2-dia-4-piernas.xlsx');
});

// TC-3
it('falls back to rutina / sin-nombre when a name has no slug characters', function () {
    $routine = Routine::factory()->for(User::factory())->create(['name' => 'Volumen Invierno']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 1]);
    $day = CycleDay::factory()->for($cycle)->create(['label' => '!!!', 'order' => 3]);

    expect(exportService()->handle($routine, $day)->filename)
        ->toBe('volumen-invierno-ciclo-1-dia-3-sin-nombre.xlsx');
});

// TC-4
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

// TC-5
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
