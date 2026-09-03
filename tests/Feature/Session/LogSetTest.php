<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function setsUrl(TrainingSession $session): string
{
    return "/api/v1/sessions/{$session->uuid}/sets";
}

// TC-8
it('logs a planned set via day_exercise_id', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();

    $response = $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $dayExercise->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.exercise.id', $dayExercise->exercise->uuid)
        ->assertJsonPath('data.set_number', 1)
        ->assertJsonPath('data.weight_kg', 80)
        ->assertJsonPath('data.reps', 8)
        ->assertJsonPath('data.rpe', null)
        ->assertJsonPath('data.note', null);

    expect($response->json('data.id'))->toMatch(uuidV4Pattern());

    $this->assertDatabaseHas('set_logs', [
        'uuid' => $response->json('data.id'),
        'session_id' => $session->id,
        'exercise_id' => $dayExercise->exercise_id,
        'set_number' => 1,
    ]);
    $this->assertDatabaseCount('set_logs', 1);
});

// TC-9
it('logs a free-session set via exercise_id', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 100.5,
        'reps' => 5,
        'rpe' => 8,
        'note' => 'felt strong',
    ])
        ->assertCreated()
        ->assertJsonPath('data.exercise.id', $exercise->uuid)
        ->assertJsonPath('data.rpe', 8)
        ->assertJsonPath('data.note', 'felt strong');

    $this->assertDatabaseHas('set_logs', [
        'session_id' => $session->id,
        'exercise_id' => $exercise->id,
        'set_number' => 1,
    ]);
});

// TC-10
it('allows an off-plan exercise_id on a planned session', function () {
    $session = openPlannedSession($this->user);
    $extra = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $extra->uuid,
        'set_number' => 1,
        'weight_kg' => 20,
        'reps' => 12,
    ])
        ->assertCreated()
        ->assertJsonPath('data.exercise.id', $extra->uuid);

    $this->assertDatabaseCount('set_logs', 1);
});

// TC-11
it('accepts multiple sets of the same exercise with incrementing set_number', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    foreach ([1, 2, 3] as $number) {
        $this->actingAs($this->user)->postJson(setsUrl($session), [
            'exercise_id' => $exercise->uuid,
            'set_number' => $number,
            'weight_kg' => 60,
            'reps' => 10,
        ])->assertCreated();
    }

    $this->assertDatabaseCount('set_logs', 3);
    expect(SetLog::query()->orderBy('set_number')->pluck('set_number')->all())->toBe([1, 2, 3]);
});

// TC-12
it('treats rpe and note as optional', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])
        ->assertCreated()
        ->assertJsonPath('data.rpe', null)
        ->assertJsonPath('data.note', null);

    $this->assertDatabaseHas('set_logs', ['session_id' => $session->id, 'rpe' => null, 'note' => null]);
});

// TC-13
it('rejects sending both day_exercise_id and exercise_id', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $dayExercise->uuid,
        'exercise_id' => Exercise::factory()->create()->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['day_exercise_id', 'exercise_id'], 'data.errors');

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-14
it('rejects sending neither day_exercise_id nor exercise_id', function () {
    $session = openFreeSession($this->user);

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['day_exercise_id', 'exercise_id'], 'data.errors');
});

// TC-15
it('rejects a day_exercise_id that is not a uuid or is unknown', function (string $value) {
    $session = openPlannedSession($this->user);

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $value,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('day_exercise_id', 'data.errors');

    $this->assertDatabaseCount('set_logs', 0);
})->with([
    'not a uuid' => 'not-a-uuid',
    'unknown uuid' => fn () => (string) Str::uuid(),
]);

// TC-16
it('rejects a well-formed exercise_id absent from the catalogue', function () {
    $session = openFreeSession($this->user);

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => (string) Str::uuid(),
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('exercise_id', 'data.errors');
});

// TC-17
it('rejects an out-of-range weight_kg', function (mixed $weight) {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => $weight,
        'reps' => 8,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('weight_kg', 'data.errors');

    $this->assertDatabaseCount('set_logs', 0);
})->with(['negative' => -1, 'too heavy' => 1000.01, 'too precise' => 80.123]);

// TC-18
it('rejects an out-of-range reps', function (mixed $reps) {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => $reps,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reps', 'data.errors');
})->with(['zero' => 0, 'too many' => 101, 'fractional' => 8.5]);

// TC-19
it('rejects an rpe out of range or off the 0.5 grid', function (mixed $rpe) {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 8,
        'rpe' => $rpe,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rpe', 'data.errors');
})->with(['negative' => -0.5, 'over ten' => 10.5, 'off grid' => 7.3]);

// TC-20
it('rejects a note longer than 1000 characters', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 8,
        'note' => str_repeat('x', 1001),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('note', 'data.errors');
});

// TC-21
it('rejects a non-contiguous set_number', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    // No sets yet — expected 1, send 2.
    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 2,
        'weight_kg' => 60,
        'reps' => 8,
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'NON_CONTIGUOUS_SET_NUMBER')
        ->assertJsonMissingPath('data.errors');

    $this->assertDatabaseCount('set_logs', 0);

    SetLog::factory()->for($session, 'session')->for($exercise)->create(['set_number' => 1]);

    foreach ([1, 3] as $number) {
        $this->actingAs($this->user)->postJson(setsUrl($session), [
            'exercise_id' => $exercise->uuid,
            'set_number' => $number,
            'weight_kg' => 60,
            'reps' => 8,
        ])->assertStatus(409)->assertJsonPath('data.code', 'NON_CONTIGUOUS_SET_NUMBER');
    }

    $this->assertDatabaseCount('set_logs', 1);
});

// TC-22
it('restarts set_number at 1 for a second exercise', function () {
    $session = openFreeSession($this->user);
    $a = Exercise::factory()->create();
    $b = Exercise::factory()->create();

    SetLog::factory()->for($session, 'session')->for($a)->create(['set_number' => 1]);

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $b->uuid,
        'set_number' => 1,
        'weight_kg' => 40,
        'reps' => 12,
    ])->assertCreated();

    $this->assertDatabaseCount('set_logs', 2);
});

// TC-23
it('rejects a day_exercise_id on a free session', function () {
    $session = openFreeSession($this->user);
    $dayExercise = DayExercise::factory()->for(
        CycleDay::factory()->for(Cycle::factory()->active())
    )->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $dayExercise->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'DAY_EXERCISE_NOT_IN_SESSION');

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-23
it('rejects a day_exercise_id from another day of the same cycle', function () {
    $session = openPlannedSession($this->user);
    $otherDay = CycleDay::query()
        ->where('cycle_id', $session->cycleDay->cycle_id)
        ->whereKeyNot($session->cycle_day_id)
        ->first();
    $foreignDayExercise = $otherDay->dayExercises()->first();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $foreignDayExercise->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'DAY_EXERCISE_NOT_IN_SESSION');
});

// TC-23
it('rejects a day_exercise_id from another users routine', function () {
    $session = openFreeSession($this->user);
    $otherSession = openPlannedSession(User::factory()->create());
    $foreignDayExercise = $otherSession->cycleDay->dayExercises->first();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $foreignDayExercise->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'DAY_EXERCISE_NOT_IN_SESSION');
});

// TC-24
it('rejects logging a set into a completed session', function () {
    $session = TrainingSession::factory()->for($this->user)
        ->for(Routine::factory()->for($this->user))->completed()->create();
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED');

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-25
it('forbids logging a set into another users session', function () {
    $otherSession = openFreeSession(User::factory()->create());
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($otherSession), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-26
it('returns 404 for an unknown or non-uuid session', function (string $segment, int $status) {
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson("/api/v1/sessions/{$segment}/sets", [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])->assertStatus($status);
})->with([
    'unknown uuid' => [fn () => (string) Str::uuid(), 404],
    'non-uuid' => ['42', 404],
]);

// TC-27
it('rejects an unauthenticated set log', function () {
    $session = openFreeSession(User::factory()->create());
    $exercise = Exercise::factory()->create();

    $this->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-28
it('exposes uuids only and serialises types correctly', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $response = $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 82.5,
        'reps' => 8,
        'rpe' => 7.5,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.id', SetLog::query()->sole()->uuid)
        ->assertJsonPath('data.exercise.id', $exercise->uuid)
        ->assertJsonMissingPath('data.session_id')
        ->assertJsonMissingPath('data.exercise_id')
        ->assertJsonMissingPath('data.exercise.created_by_ai')
        ->assertJsonPath('data.weight_kg', 82.5)
        ->assertJsonPath('data.rpe', 7.5);

    expect($response->json('data.created_at'))->toMatch(iso8601Pattern());
});

// TC-29
it('never touches another users sets', function () {
    $other = User::factory()->create();
    $otherSession = openFreeSession($other);
    SetLog::factory()->for($otherSession, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'exercise_id' => $exercise->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])->assertCreated();

    expect($otherSession->sets()->count())->toBe(1)
        ->and(SetLog::query()->latest('id')->first()->session_id)->toBe($session->id);
});

// TC-30
it('renders the created set without a strict-mode lazy load', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();

    $this->actingAs($this->user)->postJson(setsUrl($session), [
        'day_exercise_id' => $dayExercise->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertCreated()
        ->assertJsonPath('data.exercise.name', $dayExercise->exercise->name);
});
