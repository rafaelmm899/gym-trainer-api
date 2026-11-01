<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/plans/session-redesign-data-spec.md §8, TC-29. The migration needs the
// app + a real DB, so it is bound here rather than in `tests/Pest.php`.
uses(TestCase::class, RefreshDatabase::class);

// TC-29
it('backfills day_exercise_id only for unambiguous sets', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->for($routine)->create();

    $squat = Exercise::factory()->create();
    $curl = Exercise::factory()->create();
    $offPlan = Exercise::factory()->create();

    $uniqueDay = CycleDay::factory()->for($cycle)->create(['order' => 1]);
    $uniquePrescription = DayExercise::factory()->for($uniqueDay)->for($squat)->create(['order' => 1]);

    $repeatingDay = CycleDay::factory()->for($cycle)->create(['order' => 2]);
    DayExercise::factory()->for($repeatingDay)->for($curl)->create(['order' => 1]);
    DayExercise::factory()->for($repeatingDay)->for($curl)->create(['order' => 2]);

    $session = fn (?CycleDay $day) => TrainingSession::factory()->for($user)->for($routine)->completed()
        ->create(['cycle_day_id' => $day?->id]);

    $unique = SetLog::factory()->for($session($uniqueDay), 'session')->for($squat)->create(['set_number' => 1]);
    $repeated = SetLog::factory()->for($session($repeatingDay), 'session')->for($curl)->create(['set_number' => 1]);
    $off = SetLog::factory()->for($session($uniqueDay), 'session')->for($offPlan)->create(['set_number' => 1]);
    $free = SetLog::factory()->for($session(null), 'session')->for($squat)->create(['set_number' => 1]);

    $migration = require database_path('migrations/2026_11_01_120100_backfill_day_exercise_id_on_set_logs.php');
    $migration->up();

    expect($unique->fresh()->day_exercise_id)->toBe($uniquePrescription->id)
        ->and($repeated->fresh()->day_exercise_id)->toBeNull()
        ->and($off->fresh()->day_exercise_id)->toBeNull()
        ->and($free->fresh()->day_exercise_id)->toBeNull();
});
