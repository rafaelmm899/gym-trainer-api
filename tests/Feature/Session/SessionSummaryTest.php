<?php

use App\Jobs\Session\SessionAnalysisJob;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

// docs/plans/session-redesign-data-spec.md §8, TC-35..TC-43.

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
    $this->exercise = Exercise::factory()->create();
});

function cycleDayOf(Routine $routine, int $sequence, int $order = 1): CycleDay
{
    $cycle = Cycle::query()->where('routine_id', $routine->id)->where('sequence_number', $sequence)->first()
        ?? Cycle::factory()->for($routine)->create(['sequence_number' => $sequence]);

    return CycleDay::factory()->for($cycle)->create(['order' => $order]);
}

function summaryOf(object $test, TrainingSession $session): array
{
    return $test->actingAs($session->user)->getJson("/api/v1/sessions/{$session->uuid}")->assertOk()->json('data.summary');
}

// TC-35
it('summarises a completed session', function () {
    $day = cycleDayOf($this->routine, 1);
    $session = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10], [60, 10], [60, 8]], now()->subDay(), $day);
    $session->update(['completed_at' => $session->started_at->addSeconds(3605)]);
    foreach ([[40, 12], [20, 15]] as [$weight, $reps]) {
        $other = Exercise::factory()->create();
        foreach ([1, 2, 3] as $number) {
            SetLog::factory()->for($session, 'session')->for($other)
                ->create(['set_number' => $number, 'weight_kg' => $weight, 'reps' => $reps]);
        }
    }

    expect(summaryOf($this, $session))->toEqual([
        'exercises_count' => 3,
        'sets_count' => 9,
        'total_volume_kg' => 4020.0,
        'duration_seconds' => 3605,
        'volume_change_pct' => null,
    ]);
});

// TC-36
it('summarises an in-progress session partially, without a comparison', function () {
    completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subWeek(), cycleDayOf($this->routine, 1));
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->planned(cycleDayOf($this->routine, 2))
        ->create(['started_at' => now()->subHour()]);
    SetLog::factory()->count(2)->for($session, 'session')->for($this->exercise)
        ->sequence(['set_number' => 1], ['set_number' => 2])
        ->create(['weight_kg' => 55, 'reps' => 10]);

    expect(summaryOf($this, $session))->toEqual([
        'exercises_count' => 1,
        'sets_count' => 2,
        'total_volume_kg' => 1100.0,
        'duration_seconds' => null,
        'volume_change_pct' => null,
    ]);
});

// TC-37
it('returns an all-zero summary when a session is created', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);
    $day = $routine->cycle->cycleDays->first();

    $this->actingAs($user)->postJson("/api/v1/routines/{$routine->uuid}/sessions", ['day' => $day->uuid])
        ->assertCreated()
        ->assertJsonPath('data.summary', [
            'exercises_count' => 0,
            'sets_count' => 0,
            'total_volume_kg' => 0,
            'duration_seconds' => null,
            'volume_change_pct' => null,
        ]);
});

// TC-38
it('compares with the same day of the immediately previous cycle', function () {
    completedSessionWith($this->user, $this->routine, $this->exercise, [[100, 10]], now()->subDays(13), cycleDayOf($this->routine, 1, 2));
    completedSessionWith($this->user, $this->routine, $this->exercise, [[10, 10]], now()->subDays(14), cycleDayOf($this->routine, 1, 1));
    $current = completedSessionWith($this->user, $this->routine, $this->exercise, [[110, 10]], now()->subDay(), cycleDayOf($this->routine, 2, 2));

    expect(summaryOf($this, $current)['volume_change_pct'])->toEqual(10.0);
});

// TC-39
it('takes the most recent baseline when the day was done twice', function () {
    $day = cycleDayOf($this->routine, 1, 1);
    completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(14), $day);
    completedSessionWith($this->user, $this->routine, $this->exercise, [[100, 10]], now()->subDays(13), $day);
    $current = completedSessionWith($this->user, $this->routine, $this->exercise, [[110, 10]], now()->subDay(), cycleDayOf($this->routine, 2, 1));

    expect(summaryOf($this, $current)['volume_change_pct'])->toEqual(10.0);
});

// TC-40
it('has no volume change when there is nothing valid to compare', function () {
    $session = fn (Routine $routine, array $sets, $day = null, int $daysAgo = 1) => completedSessionWith(
        $this->user, $routine, $this->exercise, $sets, now()->subDays($daysAgo), $day,
    );

    $firstCycle = $session($this->routine, [[100, 10]], cycleDayOf($this->routine, 1, 5), 30);

    $skipped = $session($this->routine, [[100, 10]], cycleDayOf($this->routine, 3, 5), 10);
    $free = $session($this->routine, [[100, 10]]);

    $session($this->routine, [[0, 10]], cycleDayOf($this->routine, 1, 6), 31);
    $afterZero = $session($this->routine, [[100, 10]], cycleDayOf($this->routine, 2, 6), 20);

    $other = Routine::factory()->for($this->user)->archived()->create();
    $session($other, [[100, 10]], cycleDayOf($other, 1, 7), 32);
    $foreignBaseline = $session($this->routine, [[100, 10]], cycleDayOf($this->routine, 2, 7), 21);

    foreach ([$firstCycle, $skipped, $free, $afterZero, $foreignBaseline] as $subject) {
        expect(summaryOf($this, $subject)['volume_change_pct'])->toBeNull();
    }
});

// TC-41
it('summarises every listed session in a constant number of queries', function () {
    $baselineDay = cycleDayOf($this->routine, 1, 1);
    $currentDay = cycleDayOf($this->routine, 2, 1);
    completedSessionWith($this->user, $this->routine, $this->exercise, [[100, 10]], now()->subDays(60), $baselineDay);

    foreach (range(1, 14) as $daysAgo) {
        completedSessionWith($this->user, $this->routine, $this->exercise, [[100, 10], [100, 5]], now()->subDays($daysAgo), $currentDay);
    }

    $list = fn (int $perPage) => $this->actingAs($this->user)
        ->getJson("/api/v1/routines/{$this->routine->uuid}/sessions?per_page={$perPage}")
        ->assertOk();

    $queriesFor = fn (int $perPage): int => countQueries(fn () => $list($perPage));

    expect($queriesFor(15))->toBe($queriesFor(3));

    $items = $list(15)->json('data');

    expect($items)->toHaveCount(15);

    foreach (array_slice($items, 0, 14) as $item) {
        expect($item['summary']['sets_count'])->toBe(2)
            ->and($item['summary']['total_volume_kg'])->toEqual(1500.0)
            ->and($item['summary']['volume_change_pct'])->toEqual(50.0);
    }
});

// TC-42
it('includes the summary when completing a session and importing a workbook', function () {
    Bus::fake([SessionAnalysisJob::class]);

    $closer = User::factory()->create();
    $session = openPlannedSession($closer);
    SetLog::factory()->for($session, 'session')->for($session->cycleDay->dayExercises->first()->exercise)
        ->create(['set_number' => 1, 'weight_kg' => 80, 'reps' => 5]);

    $this->actingAs($closer)->postJson("/api/v1/sessions/{$session->uuid}/complete")
        ->assertOk()
        ->assertJsonPath('data.summary.sets_count', 1)
        ->assertJsonPath('data.summary.exercises_count', 1);

    expect(TrainingSession::query()->findOrFail($session->id)->completed_at)->not->toBeNull();

    $importer = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($importer);
    prescribeExercise($day, 'Sentadilla', 1);

    $response = $this->actingAs($importer)->post(
        "/api/v1/routines/{$routine->uuid}/cycle-days/{$day->uuid}/import",
        ['file' => buildXlsxUploadedFile([importRow('Sentadilla', 100, 8), importRow('Sentadilla', 100, 6)])],
    )->assertCreated();

    expect($response->json('data.summary.sets_count'))->toBe(2)
        ->and($response->json('data.summary.duration_seconds'))->not->toBeNull();
});

// TC-43
it('keeps every pre-existing key of the session payloads', function () {
    $day = cycleDayOf($this->routine, 1);
    $session = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10], [60, 8]], now()->subDay(), $day);
    DayExercise::factory()->for($day)->for($this->exercise)->create(['order' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);

    $this->actingAs($this->user);
    $detail = $this->getJson("/api/v1/sessions/{$session->uuid}")->assertOk()->json('data');
    $list = $this->getJson("/api/v1/routines/{$this->routine->uuid}/sessions")->assertOk()->json('data.0');

    $sessionKeys = ['id', 'status', 'analysis_state', 'note', 'perceived_effort', 'started_at', 'completed_at', 'created_at', 'updated_at', 'cycle_day'];

    expect(array_keys($detail))->toContain(...$sessionKeys, ...['sets', 'recommendations'])
        ->and(array_keys($list))->toContain(...$sessionKeys)
        ->and(array_keys($detail['sets'][0]))->toContain('id', 'exercise', 'set_number', 'weight_kg', 'reps', 'rpe', 'note', 'created_at', 'updated_at')
        ->and(array_keys($detail['recommendations'][0]))->toContain('id', 'exercise', 'target_weight_kg', 'target_sets', 'target_rep_min', 'target_rep_max', 'action', 'explanation')
        ->and(array_keys($detail['cycle_day']['exercises'][0]))->toContain('id', 'order', 'name', 'sets', 'rep_min', 'rep_max', 'target_weight_kg', 'target_rpe', 'rest_seconds', 'rationale');
});
