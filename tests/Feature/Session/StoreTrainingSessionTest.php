<?php

use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    AthleteProfile::factory()->for($this->user)->create();
});

function sessionsUrl(Routine $routine): string
{
    return "/api/v1/routines/{$routine->uuid}/sessions";
}

// TC-1
it('opens a planned session against a day of the active cycle', function () {
    $routine = trainingRoutineWithCycle($this->user);
    $day = $routine->cycle->cycleDays->first();

    $response = $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $day->uuid]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.analysis_state', 'pending')
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonPath('data.cycle_day.id', $day->uuid);

    expect($response->json('data.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.started_at'))->toMatch(iso8601Pattern());

    $this->assertDatabaseHas('training_sessions', [
        'uuid' => $response->json('data.id'),
        'user_id' => $this->user->id,
        'routine_id' => $routine->id,
        'cycle_day_id' => $day->id,
        'status' => 'in_progress',
        'analysis_state' => 'pending',
        'completed_at' => null,
    ]);
    $this->assertDatabaseCount('training_sessions', 1);
});

// TC-2
it('opens a free session when no day is sent', function () {
    $routine = trainingRoutineWithCycle($this->user);

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), [])
        ->assertCreated()
        ->assertJsonPath('data.cycle_day', null);

    $this->assertDatabaseHas('training_sessions', [
        'user_id' => $this->user->id,
        'routine_id' => $routine->id,
        'cycle_day_id' => null,
        'status' => 'in_progress',
    ]);
});

// TC-3
it('treats a null, empty or whitespace day as a free session', function (mixed $day) {
    $routine = trainingRoutineWithCycle($this->user);

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $day])
        ->assertCreated()
        ->assertJsonPath('data.cycle_day', null);

    $this->assertDatabaseHas('training_sessions', ['routine_id' => $routine->id, 'cycle_day_id' => null]);
})->with([
    'null' => null,
    'empty' => '',
    'whitespace' => '   ',
]);

// TC-4
it('embeds the full prescription tree for the linked cycle day', function () {
    $routine = trainingRoutineWithCycle($this->user);
    $day = $routine->cycle->cycleDays->first();

    $response = $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $day->uuid])
        ->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'cycle_day' => [
                    'id', 'order', 'label', 'focus_muscle_groups', 'rationale',
                    'exercises' => [
                        ['id', 'order', 'name', 'sets', 'rep_min', 'rep_max', 'target_weight_kg', 'target_rpe', 'rest_seconds', 'rationale'],
                    ],
                ],
            ],
        ]);

    expect($response->json('data.cycle_day.exercises'))->toHaveCount(3)
        ->and(array_column($response->json('data.cycle_day.exercises'), 'order'))->toBe([1, 2, 3]);
});

// TC-5
it('exposes uuids only and never internal ids or a routine block', function () {
    $routine = trainingRoutineWithCycle($this->user);
    $day = $routine->cycle->cycleDays->first();

    $response = $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $day->uuid])
        ->assertCreated()
        ->assertJsonMissingPath('data.user_id')
        ->assertJsonMissingPath('data.routine_id')
        ->assertJsonMissingPath('data.routine')
        ->assertJsonMissingPath('data.cycle_day.cycle_id')
        ->assertJsonMissingPath('data.cycle_day.exercises.0.exercise_id');

    expect($response->json('data.cycle_day.id'))->toMatch(uuidV4Pattern());
});

// TC-6
it('serialises enums as strings and dates as ISO-8601', function () {
    $routine = trainingRoutineWithCycle($this->user);

    $response = $this->actingAs($this->user)->postJson(sessionsUrl($routine), [])->assertCreated();

    expect($response->json('data.status'))->toBe('in_progress')
        ->and($response->json('data.analysis_state'))->toBe('pending')
        ->and($response->json('data.started_at'))->toMatch(iso8601Pattern())
        ->and($response->json('data.created_at'))->toMatch(iso8601Pattern());
});

// TC-7
it('rejects a day that is not a uuid', function () {
    $routine = trainingRoutineWithCycle($this->user);

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => 'not-a-uuid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('day', 'data.errors');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-8
it('rejects a well-formed day uuid that does not exist', function () {
    $routine = trainingRoutineWithCycle($this->user);

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('day', 'data.errors');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-9
it('rejects a day from another users routine', function () {
    $routine = trainingRoutineWithCycle($this->user);
    $foreignRoutine = trainingRoutineWithCycle(User::factory()->create());
    $foreignDay = $foreignRoutine->cycle->cycleDays->first();

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $foreignDay->uuid])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE')
        ->assertJsonMissingPath('data.errors');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-10
it('rejects a day from an older, non-active cycle of the same routine', function () {
    $routine = Routine::factory()->for($this->user)->create();

    $oldCycle = Cycle::factory()->incomplete()->for($routine)->create(['sequence_number' => 1]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create(['order' => 1]);

    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $oldDay->uuid])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-11
it('rejects opening a second session while one is in progress', function () {
    $routine = trainingRoutineWithCycle($this->user);
    TrainingSession::factory()->for($this->user)->for($routine)->create();

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), [])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_IN_PROGRESS');

    $this->assertDatabaseCount('training_sessions', 1);
});

// TC-12
it('rejects a new session when the open one is under a different routine', function () {
    $archived = Routine::factory()->for($this->user)->archived()->create();
    TrainingSession::factory()->for($this->user)->for($archived)->create();

    $active = trainingRoutineWithCycle($this->user);

    $this->actingAs($this->user)->postJson(sessionsUrl($active), [])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_IN_PROGRESS');
});

// TC-13
it('allows a new session when only completed sessions exist', function () {
    $routine = trainingRoutineWithCycle($this->user);
    TrainingSession::factory()->for($this->user)->for($routine)->completed()->create();

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), [])->assertCreated();

    $this->assertDatabaseCount('training_sessions', 2);
});

// TC-14
it('allows re-training a day that already has a completed session', function () {
    $routine = trainingRoutineWithCycle($this->user);
    $day = $routine->cycle->cycleDays->first();
    TrainingSession::factory()->for($this->user)->for($routine)->completed()->create(['cycle_day_id' => $day->id]);

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $day->uuid])->assertCreated();

    expect(TrainingSession::where('cycle_day_id', $day->id)->count())->toBe(2);
});

// TC-15
it('rejects opening a session under an archived routine', function () {
    $archived = Routine::factory()->for($this->user)->archived()->create();
    $cycle = Cycle::factory()->active()->for($archived)->create();
    $day = CycleDay::factory()->for($cycle)->create(['order' => 1]);

    $this->actingAs($this->user)->postJson(sessionsUrl($archived), [])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'ROUTINE_NOT_ACTIVE');

    $this->actingAs($this->user)->postJson(sessionsUrl($archived), ['day' => $day->uuid])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'ROUTINE_NOT_ACTIVE');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-16
it('forbids opening a session under another users routine', function () {
    $foreignRoutine = trainingRoutineWithCycle(User::factory()->create());

    $this->actingAs($this->user)->postJson(sessionsUrl($foreignRoutine), [])
        ->assertStatus(403)
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-17
it('returns 404 for an unknown routine uuid', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines/'.Str::uuid().'/sessions', [])
        ->assertStatus(404)
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-18
it('returns 404 for a non-uuid routine segment', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines/42/sessions', [])
        ->assertStatus(404);
});

// TC-19
it('rejects an unauthenticated request', function () {
    $routine = trainingRoutineWithCycle(User::factory()->create());

    $this->postJson(sessionsUrl($routine), [])
        ->assertStatus(401)
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-20
it('never touches another users sessions', function () {
    $other = User::factory()->create();
    $otherRoutine = trainingRoutineWithCycle($other);
    TrainingSession::factory()->for($other)->for($otherRoutine)->create();

    $routine = trainingRoutineWithCycle($this->user);

    $response = $this->actingAs($this->user)->postJson(sessionsUrl($routine), [])->assertCreated();

    expect($other->trainingSessions()->count())->toBe(1)
        ->and(TrainingSession::where('uuid', $response->json('data.id'))->value('user_id'))->toBe($this->user->id);
});

// TC-21
it('renders the cycle-day tree without a strict-mode lazy load', function () {
    $routine = trainingRoutineWithCycle($this->user);
    $day = $routine->cycle->cycleDays->first();

    $this->actingAs($this->user)->postJson(sessionsUrl($routine), ['day' => $day->uuid])
        ->assertCreated()
        ->assertJsonMissingPath('data.user')
        ->assertJsonMissingPath('data.routine')
        ->assertJsonCount(3, 'data.cycle_day.exercises');
});
