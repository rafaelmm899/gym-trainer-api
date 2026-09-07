<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Services\Cycle\ProgressionSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TC-21..TC-26 — generate-next-cycle-spec.md §8
uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{routine: Routine, cycle: Cycle, day: CycleDay, exercise: Exercise}
 */
function progressionFixture(): array
{
    $routine = Routine::factory()->create();
    $cycle = Cycle::factory()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();
    $exercise = Exercise::factory()->create();

    DayExercise::factory()->for($day, 'cycleDay')->for($exercise)->create([
        'sets' => 3,
        'rep_min' => 8,
        'rep_max' => 12,
        'target_weight_kg' => 82.5,
    ]);

    return compact('routine', 'cycle', 'day', 'exercise');
}

it('marks performed false when zero sets were logged in the outgoing cycle', function () {
    ['routine' => $routine, 'cycle' => $cycle, 'exercise' => $exercise] = progressionFixture();

    $summary = app(ProgressionSummaryService::class)->summarize($routine, $cycle);

    expect($summary)->toHaveCount(1)
        ->and($summary[$exercise->id]->performed)->toBeFalse()
        ->and($summary[$exercise->id]->actualAvgWeightKg)->toBeNull()
        ->and($summary[$exercise->id]->trend)->toBe('insufficient_data');
});

it('computes actual averages from completed sessions logged in the outgoing cycle', function () {
    ['routine' => $routine, 'cycle' => $cycle, 'day' => $day, 'exercise' => $exercise] = progressionFixture();

    $session = TrainingSession::factory()->for($routine)->completed()->planned($day)->create();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1, 'weight_kg' => 40, 'reps' => 10, 'rpe' => 7]);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 2, 'weight_kg' => 42.5, 'reps' => 9, 'rpe' => 8]);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 3, 'weight_kg' => 42.5, 'reps' => 8, 'rpe' => 9]);

    $entry = app(ProgressionSummaryService::class)->summarize($routine, $cycle)[$exercise->id];

    expect($entry->performed)->toBeTrue()
        ->and($entry->actualAvgWeightKg)->toBe(41.67)
        ->and($entry->actualAvgReps)->toBe(9.0)
        ->and($entry->actualMaxRpe)->toBe(9.0);
});

it('ignores sets from a not-yet-completed session', function () {
    ['routine' => $routine, 'cycle' => $cycle, 'day' => $day, 'exercise' => $exercise] = progressionFixture();

    $session = TrainingSession::factory()->for($routine)->planned($day)->create();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create();

    $entry = app(ProgressionSummaryService::class)->summarize($routine, $cycle)[$exercise->id];

    expect($entry->performed)->toBeFalse();
});

it('trend is insufficient_data with fewer than 2 completed sessions for the exercise, ever', function () {
    ['routine' => $routine, 'cycle' => $cycle, 'day' => $day, 'exercise' => $exercise] = progressionFixture();

    $session = TrainingSession::factory()->for($routine)->completed()->planned($day)->create();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create();

    $entry = app(ProgressionSummaryService::class)->summarize($routine, $cycle)[$exercise->id];

    expect($entry->trend)->toBe('insufficient_data')
        ->and($entry->plateauSignal)->toBeFalse();
});

it('derives trend and plateau signal from the exercise\'s last two completed sessions, routine-wide', function (float $olderWeight, float $newerWeight, string $expectedTrend, bool $expectedPlateau) {
    ['routine' => $routine, 'cycle' => $cycle, 'day' => $day, 'exercise' => $exercise] = progressionFixture();

    $olderSession = TrainingSession::factory()->for($routine)->completed()->planned($day)
        ->create(['completed_at' => now()->subWeek()]);
    SetLog::factory()->for($olderSession, 'session')->for($exercise, 'exercise')->create(['weight_kg' => $olderWeight]);

    $newerSession = TrainingSession::factory()->for($routine)->completed()->planned($day)
        ->create(['completed_at' => now()]);
    SetLog::factory()->for($newerSession, 'session')->for($exercise, 'exercise')->create(['weight_kg' => $newerWeight]);

    $entry = app(ProgressionSummaryService::class)->summarize($routine, $cycle)[$exercise->id];

    expect($entry->trend)->toBe($expectedTrend)
        ->and($entry->plateauSignal)->toBe($expectedPlateau);
})->with([
    'up' => [40.0, 42.5, 'up', false],
    'down' => [42.5, 40.0, 'down', true],
    'flat' => [40.0, 40.0, 'flat', true],
]);

it('only reports exercises prescribed in the outgoing cycle', function () {
    ['routine' => $routine, 'cycle' => $cycle] = progressionFixture();

    DayExercise::factory()->for(CycleDay::factory()->for($cycle)->create(['order' => 2]), 'cycleDay')->create();
    DayExercise::factory()->for(CycleDay::factory()->for($cycle)->create(['order' => 3]), 'cycleDay')->create();

    $freeSessionExercise = Exercise::factory()->create();
    $freeSession = TrainingSession::factory()->for($routine)->completed()->create();
    SetLog::factory()->for($freeSession, 'session')->for($freeSessionExercise, 'exercise')->create();

    $summary = app(ProgressionSummaryService::class)->summarize($routine, $cycle);

    expect($summary)->toHaveCount(3)
        ->and($summary)->not->toHaveKey($freeSessionExercise->id);
});
