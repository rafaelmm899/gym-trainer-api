<?php

use App\Enums\Session\SessionStatus;
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

/**
 * @return array{0: TrainingSession, 1: SetLog}
 */
function openSetFixture(User $user, array $attributes = []): array
{
    $session = openFreeSession($user);
    $set = SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(array_merge([
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
        'rpe' => null,
        'note' => null,
    ], $attributes));

    return [$session, $set];
}

function setUrl(TrainingSession $session, SetLog $set): string
{
    return "/api/v1/sessions/{$session->uuid}/sets/{$set->uuid}";
}

// TC-31
it('corrects a set while the session is in progress', function () {
    [$session, $set] = openSetFixture($this->user);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), [
        'weight_kg' => 82.5,
        'reps' => 7,
        'rpe' => 8,
        'note' => 'last set hard',
    ])
        ->assertOk()
        ->assertJsonPath('data.weight_kg', 82.5)
        ->assertJsonPath('data.reps', 7)
        ->assertJsonPath('data.rpe', 8)
        ->assertJsonPath('data.note', 'last set hard');

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => 82.5, 'reps' => 7]);
});

// TC-32
it('nulls rpe and note when they are omitted', function () {
    [$session, $set] = openSetFixture($this->user, ['rpe' => 8, 'note' => 'x']);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), [
        'weight_kg' => 80,
        'reps' => 8,
    ])
        ->assertOk()
        ->assertJsonPath('data.rpe', null)
        ->assertJsonPath('data.note', null);

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'rpe' => null, 'note' => null]);
});

// TC-33
it('rejects a missing weight_kg or reps', function (array $payload, string $field) {
    [$session, $set] = openSetFixture($this->user);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field, 'data.errors');

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => 80, 'reps' => 8]);
})->with([
    'no weight' => [['reps' => 8], 'weight_kg'],
    'no reps' => [['weight_kg' => 80], 'reps'],
]);

// TC-34
it('rejects out-of-range values', function (array $payload, string $field) {
    [$session, $set] = openSetFixture($this->user);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), array_merge(['weight_kg' => 80, 'reps' => 8], $payload))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field, 'data.errors');

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => 80, 'reps' => 8]);
})->with([
    'weight too heavy' => [['weight_kg' => 1000.01], 'weight_kg'],
    'reps zero' => [['reps' => 0], 'reps'],
    'rpe off grid' => [['rpe' => 7.3], 'rpe'],
    'note too long' => [['note' => str_repeat('x', 1001)], 'note'],
]);

// TC-35
it('rejects correcting a set in a completed session', function () {
    [$session, $set] = openSetFixture($this->user);
    $session->update(['status' => SessionStatus::Completed, 'completed_at' => now()]);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), [
        'weight_kg' => 100,
        'reps' => 3,
    ])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED');

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => 80]);
});

// TC-36
it('returns 404 when the set belongs to a different session', function () {
    $session = openFreeSession($this->user);
    $otherSession = TrainingSession::factory()->for($this->user)
        ->for(Routine::find($session->routine_id))->completed()->create();
    $set = SetLog::factory()->for($otherSession, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), ['weight_kg' => 80, 'reps' => 8])
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => $set->weight_kg]);
});

// TC-37
it('forbids correcting a set whose session belongs to another user', function () {
    $other = User::factory()->create();
    $session = openFreeSession($other);
    $set = SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), ['weight_kg' => 80, 'reps' => 8])
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');
});

// TC-38
it('returns 404 for an unknown or non-uuid set', function (string $segment) {
    $session = openFreeSession($this->user);

    $this->actingAs($this->user)->putJson("/api/v1/sessions/{$session->uuid}/sets/{$segment}", [
        'weight_kg' => 80,
        'reps' => 8,
    ])->assertNotFound();
})->with([
    'unknown uuid' => fn () => (string) Str::uuid(),
    'non-uuid' => '42',
]);

// TC-39
it('rejects an unauthenticated correction', function () {
    [$session, $set] = openSetFixture(User::factory()->create());

    $this->putJson(setUrl($session, $set), ['weight_kg' => 80, 'reps' => 8])
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-40
it('never changes set_number or the exercise through PUT', function () {
    $session = openFreeSession($this->user);
    $a = Exercise::factory()->create();
    $b = Exercise::factory()->create();
    $set = SetLog::factory()->for($session, 'session')->for($a)->create(['set_number' => 1]);

    $this->actingAs($this->user)->putJson(setUrl($session, $set), [
        'weight_kg' => 80,
        'reps' => 8,
        'set_number' => 9,
        'exercise_id' => $b->uuid,
    ])
        ->assertOk()
        ->assertJsonPath('data.set_number', 1)
        ->assertJsonPath('data.exercise.id', $a->uuid);

    $this->assertDatabaseHas('set_logs', ['id' => $set->id, 'set_number' => 1, 'exercise_id' => $a->id]);
});

// TC-41
it('returns the same body shape as the create endpoint', function () {
    [$session, $set] = openSetFixture($this->user);

    $response = $this->actingAs($this->user)->putJson(setUrl($session, $set), ['weight_kg' => 81, 'reps' => 9]);

    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'id',
            'exercise' => ['id', 'name', 'slug', 'primary_muscle_group'],
            'set_number', 'weight_kg', 'reps', 'rpe', 'note', 'created_at', 'updated_at',
        ]])
        ->assertJsonPath('data.id', $set->uuid);

    expect($response->json('data.updated_at'))->toMatch(iso8601Pattern());
});
