<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;

// docs/plans/session-redesign-data-spec.md §8, TC-1..TC-11 and TC-49.

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
    $this->cycle = Cycle::factory()->for($this->routine)->create(['sequence_number' => 3]);
    $this->day = CycleDay::factory()->for($this->cycle)->create(['order' => 1]);
    $this->exercise = Exercise::factory()->create();
    $this->prescription = DayExercise::factory()->for($this->day)->for($this->exercise)->create(['order' => 1]);
});

function previousOf(object $test, TrainingSession $session): mixed
{
    return $test->actingAs($session->user)->getJson("/api/v1/sessions/{$session->uuid}")
        ->assertOk()
        ->json('data.cycle_day.exercises.0.previous');
}

function currentPlannedSession(object $test, bool $completed = false): TrainingSession
{
    $factory = TrainingSession::factory()->for($test->user)->for($test->routine)->planned($test->day);

    return ($completed ? $factory->completed() : $factory)->create(['started_at' => now()]);
}

// TC-1
it('has a null previous for an exercise never trained', function () {
    expect(previousOf($this, currentPlannedSession($this)))->toBeNull();
});

// TC-2
it('picks the most recent completed session with the exercise, from any routine', function () {
    $archived = Routine::factory()->for($this->user)->archived()->create();
    completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(5));
    $newer = completedSessionWith($this->user, $archived, $this->exercise, [[60, 8], [62.5, 6]], now()->subDays(2));

    $previous = previousOf($this, currentPlannedSession($this));

    expect($previous['session_id'])->toBe($newer->uuid)
        ->and($previous['cycle_sequence_number'])->toBeNull()
        ->and($previous['started_at'])->toBe($newer->started_at->toIso8601String())
        ->and($previous['sets'])->toEqual([
            ['set_number' => 1, 'weight_kg' => 60.0, 'reps' => 8, 'rpe' => null],
            ['set_number' => 2, 'weight_kg' => 62.5, 'reps' => 6, 'rpe' => null],
        ]);
});

// TC-3
it('carries the cycle number of a planned previous session', function () {
    $earlierCycle = Cycle::factory()->for($this->routine)->create(['sequence_number' => 2]);
    $earlierDay = CycleDay::factory()->for($earlierCycle)->create(['order' => 1]);
    $earlier = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subWeek(), $earlierDay);

    $previous = previousOf($this, currentPlannedSession($this));

    expect($previous['session_id'])->toBe($earlier->uuid)
        ->and($previous['cycle_sequence_number'])->toBe(2);
});

// TC-4
it('ignores in-progress sessions and sessions that did not start strictly earlier', function () {
    $older = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(3));
    $current = currentPlannedSession($this, completed: true);

    $open = TrainingSession::factory()->for($this->user)->for($this->routine)->create(['started_at' => now()->subHour()]);
    SetLog::factory()->for($open, 'session')->for($this->exercise)->create(['set_number' => 1]);
    completedSessionWith($this->user, $this->routine, $this->exercise, [[70, 10]], $current->started_at);

    expect(previousOf($this, $current)['session_id'])->toBe($older->uuid);
});

// TC-5
it('returns the session before the one being viewed, not the latest', function () {
    $first = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(6));
    $viewed = TrainingSession::factory()->for($this->user)->for($this->routine)->planned($this->day)->completed()
        ->create(['started_at' => now()->subDays(4)]);
    completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10]], now()->subDays(2));

    expect(previousOf($this, $viewed)['session_id'])->toBe($first->uuid);
});

// TC-6
it('skips a more recent session that has no set of the exercise', function () {
    $older = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(5));
    completedSessionWith($this->user, $this->routine, Exercise::factory()->create(), [[20, 10]], now()->subDay());

    expect(previousOf($this, currentPlannedSession($this))['session_id'])->toBe($older->uuid);
});

// TC-7
it('never takes a previous from another user', function () {
    $mine = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(5));
    $stranger = User::factory()->create();
    completedSessionWith($stranger, Routine::factory()->for($stranger)->create(), $this->exercise, [[300, 10]], now()->subDay());

    expect(previousOf($this, currentPlannedSession($this))['session_id'])->toBe($mine->uuid);
});

// TC-8
it('includes previous in the create response', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);
    $day = $routine->cycle->cycleDays->first();
    $trained = DayExercise::query()->with('exercise')->where('cycle_day_id', $day->id)->orderBy('order')->first();
    $earlier = completedSessionWith($user, $routine, $trained->exercise, [[80, 5]], now()->subDays(3));

    $response = $this->actingAs($user)->postJson("/api/v1/routines/{$routine->uuid}/sessions", ['day' => $day->uuid])
        ->assertCreated();

    $exercises = collect($response->json('data.cycle_day.exercises'));

    expect($exercises->firstWhere('id', $trained->uuid)['previous']['session_id'])->toBe($earlier->uuid)
        ->and($exercises->where('id', '!=', $trained->uuid)->pluck('previous')->filter()->all())->toBe([]);
});

// TC-9
it('gives two prescriptions of the same exercise the same previous', function () {
    DayExercise::factory()->for($this->day)->for($this->exercise)->create(['order' => 2]);
    $earlier = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(3));

    $exercises = $this->actingAs($this->user)
        ->getJson('/api/v1/sessions/'.currentPlannedSession($this)->uuid)
        ->assertOk()
        ->json('data.cycle_day.exercises');

    expect($exercises)->toHaveCount(2)
        ->and(array_column(array_column($exercises, 'previous'), 'session_id'))->toBe([$earlier->uuid, $earlier->uuid]);
});

// TC-10
it('keeps the detail query count flat as prescriptions grow', function () {
    $queriesFor = function (int $prescribed): int {
        $user = User::factory()->create();
        $routine = Routine::factory()->for($user)->create();
        $day = CycleDay::factory()->for(Cycle::factory()->for($routine))->create();

        foreach (range(1, $prescribed) as $order) {
            $exercise = Exercise::factory()->create();
            DayExercise::factory()->for($day)->for($exercise)->create(['order' => $order]);
            completedSessionWith($user, $routine, $exercise, [[50, 10]], now()->subDays(10 + $order));
        }

        $session = TrainingSession::factory()->for($user)->for($routine)->planned($day)->create();
        $this->actingAs($user);

        return countQueries(fn () => $this->getJson("/api/v1/sessions/{$session->uuid}")->assertOk());
    };

    expect($queriesFor(6))->toBe($queriesFor(1));
});

// TC-11
it('leaves cycle payloads without a previous key', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);

    $exercise = $this->actingAs($user)->getJson("/api/v1/routines/{$routine->uuid}")
        ->assertOk()
        ->json('data.cycle.days.0.exercises.0');

    expect($exercise)->toBeArray()->not->toHaveKey('previous');
});

// TC-49
it('emits previous as null on create for a never-trained day', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);
    $day = $routine->cycle->cycleDays->first();

    $exercises = $this->actingAs($user)->postJson("/api/v1/routines/{$routine->uuid}/sessions", ['day' => $day->uuid])
        ->assertCreated()
        ->json('data.cycle_day.exercises');

    expect($exercises)->not->toBeEmpty();

    foreach ($exercises as $exercise) {
        expect($exercise)->toHaveKey('previous')->and($exercise['previous'])->toBeNull();
    }
});
