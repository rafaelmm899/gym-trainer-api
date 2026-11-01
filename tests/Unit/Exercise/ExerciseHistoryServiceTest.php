<?php

use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Exercise\ExerciseHistoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

// docs/plans/session-redesign-data-spec.md §8, TC-44..TC-46. The service
// queries the database, so the app + DB are bound here rather than in
// `tests/Pest.php`, which reserves that setup for the `Feature` suite.
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
    $this->exercise = Exercise::factory()->create();
    $this->service = app(ExerciseHistoryService::class);
});

// TC-44
it('picks the latest earlier completed session of the user that has the exercise', function () {
    $other = Exercise::factory()->create();
    $s1 = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(5));
    $s2 = completedSessionWith($this->user, $this->routine, $this->exercise, [[55, 10]], now()->subDays(3));
    $s3 = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 8], [62.5, 6]], now()->subDay());

    $inProgress = TrainingSession::factory()->for($this->user)->for($this->routine)
        ->create(['started_at' => now()->subHours(12)]);
    SetLog::factory()->for($inProgress, 'session')->for($this->exercise)->create(['set_number' => 1]);
    completedSessionWith($this->user, $this->routine, $other, [[10, 10]], now()->subHours(10));
    $stranger = User::factory()->create();
    completedSessionWith($stranger, Routine::factory()->for($stranger)->create(), $this->exercise, [[200, 1]], now()->subHours(5));

    $previous = $this->service->previousFor($this->user, now(), [$this->exercise->id]);

    expect($previous->keys()->all())->toBe([$this->exercise->id])
        ->and($previous[$this->exercise->id]->sessionId)->toBe($s3->uuid)
        ->and(array_column($previous[$this->exercise->id]->sets, 'set_number'))->toBe([1, 2])
        ->and($this->service->previousFor($this->user, $s2->started_at, [$this->exercise->id])[$this->exercise->id]->sessionId)
        ->toBe($s1->uuid)
        ->and($this->service->previousFor($this->user, $s1->started_at, [$this->exercise->id]))->toBeEmpty();
});

// TC-45
it('pages the history newest first and computes the percentage across the page boundary', function () {
    foreach ([100, 110, 99, 120, 150] as $index => $weight) {
        completedSessionWith($this->user, $this->routine, $this->exercise, [[$weight, 10]], now()->subDays(5 - $index));
    }
    $stranger = User::factory()->create();
    completedSessionWith($stranger, Routine::factory()->for($stranger)->create(), $this->exercise, [[300, 10]], now()->subHours(1));

    $pageOf = function (int $page) {
        Paginator::currentPageResolver(fn () => $page);

        return $this->service->pageFor($this->user, $this->exercise, null, 2);
    };

    $first = $pageOf(1);
    $second = $pageOf(2);
    $third = $pageOf(3);

    expect($first->total())->toBe(5)
        ->and($first->getCollection()->map->volumeKg->all())->toBe([1500.0, 1200.0])
        ->and($first->getCollection()->map->volumeChangePct->all())->toBe([25.0, 21.2])
        ->and($second->getCollection()->map->volumeKg->all())->toBe([990.0, 1100.0])
        ->and($second->getCollection()->map->volumeChangePct->all())->toBe([-10.0, 10.0])
        ->and($third->getCollection()->map->volumeKg->all())->toBe([1000.0])
        ->and($third->getCollection()->map->volumeChangePct->all())->toBe([null]);
});

// TC-46
it('builds the trend ending at the source session, with the top-set tie resolved by reps', function () {
    $sessions = collect(range(6, 1))->map(fn (int $daysAgo) => completedSessionWith(
        $this->user,
        $this->routine,
        $this->exercise,
        $daysAgo === 5 ? [[100, 5], [100, 8], [90, 12]] : [[100, 5]],
        now()->subDays($daysAgo),
    ))->values();

    $recommendation = ExerciseRecommendation::factory()->for($this->user)->for($this->routine)
        ->create(['exercise_id' => $this->exercise->id, 'source_session_id' => $sessions[3]->id]);

    $latestOnly = Exercise::factory()->create();
    $recent = collect(range(6, 1))->map(fn (int $daysAgo) => completedSessionWith(
        $this->user,
        $this->routine,
        $latestOnly,
        [[40, 10]],
        now()->subDays($daysAgo),
    ))->values();
    $unsourced = ExerciseRecommendation::factory()->for($this->user)->for($this->routine)
        ->create(['exercise_id' => $latestOnly->id, 'source_session_id' => null]);

    $trend = $this->service->trendFor($this->user, new Collection([$recommendation, $unsourced]));

    expect(array_map(fn ($point) => $point->sessionId, $trend[$recommendation->id]))
        ->toBe($sessions->take(4)->map->uuid->all())
        ->and($trend[$recommendation->id][1]->topSetReps)->toBe(8)
        ->and($trend[$recommendation->id][1]->volumeKg)->toBe(2380.0)
        ->and(array_map(fn ($point) => $point->sessionId, $trend[$unsourced->id]))
        ->toBe($recent->slice(2)->map->uuid->values()->all());
});
