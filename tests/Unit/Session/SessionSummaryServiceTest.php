<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Session\SessionSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/plans/session-redesign-data-spec.md §8, TC-50. The service queries the
// database, so the app + DB are bound here rather than in `tests/Pest.php`,
// which reserves that setup for the `Feature` suite.
uses(TestCase::class, RefreshDatabase::class);

// TC-50
it('resolves a page of mixed sessions in a constant number of queries', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $exercise = Exercise::factory()->create();
    $cycleOne = Cycle::factory()->for($routine)->create(['sequence_number' => 1]);
    $cycleTwo = Cycle::factory()->for($routine)->create(['sequence_number' => 2]);
    $day = fn (Cycle $cycle, int $order) => CycleDay::factory()->for($cycle)->create(['order' => $order]);

    completedSessionWith($user, $routine, $exercise, [[100, 10]], now()->subDays(20), $day($cycleOne, 1));
    completedSessionWith($user, $routine, $exercise, [[0, 10]], now()->subDays(19), $day($cycleOne, 2));
    completedSessionWith($user, $routine, $exercise, [[50, 10]], now()->subDays(18), $day($cycleOne, 3));

    $improved = completedSessionWith($user, $routine, $exercise, [[110, 10]], now()->subDays(6), $day($cycleTwo, 1));
    $afterZero = completedSessionWith($user, $routine, $exercise, [[10, 10]], now()->subDays(5), $day($cycleTwo, 2));
    $noBaseline = completedSessionWith($user, $routine, $exercise, [[10, 10]], now()->subDays(4), $day($cycleTwo, 9));
    $free = completedSessionWith($user, $routine, $exercise, [[20, 10], [20, 8]], now()->subDays(3));

    $open = TrainingSession::factory()->for($user)->for($routine)->planned($day($cycleTwo, 3))
        ->create(['started_at' => now()->subHour()]);
    SetLog::factory()->for($open, 'session')->for($exercise)->create(['set_number' => 1, 'weight_kg' => 55, 'reps' => 10]);

    $service = app(SessionSummaryService::class);
    $fresh = fn (array $ids) => TrainingSession::query()->with('cycleDay.cycle')->whereIn('id', $ids)->get();

    $few = $fresh([$improved->id, $free->id]);
    $all = $fresh([$improved->id, $afterZero->id, $noBaseline->id, $free->id, $open->id]);

    $queriesForFew = countQueries(fn () => $service->attach($few));
    $queriesForAll = countQueries(fn () => $service->attach($all));

    expect($queriesForAll)->toBe($queriesForFew);

    $summary = fn (TrainingSession $session) => $all->firstWhere('id', $session->id)->summary;

    expect($summary($improved)->volumeChangePct)->toBe(10.0)
        ->and($summary($improved)->totalVolumeKg)->toBe(1100.0)
        ->and($summary($improved)->durationSeconds)->toBe(3600)
        ->and($summary($afterZero)->volumeChangePct)->toBeNull()
        ->and($summary($noBaseline)->volumeChangePct)->toBeNull()
        ->and($summary($free)->volumeChangePct)->toBeNull()
        ->and($summary($free)->setsCount)->toBe(2)
        ->and($summary($free)->totalVolumeKg)->toBe(360.0)
        ->and($summary($open)->setsCount)->toBe(1)
        ->and($summary($open)->durationSeconds)->toBeNull()
        ->and($summary($open)->volumeChangePct)->toBeNull();
});
