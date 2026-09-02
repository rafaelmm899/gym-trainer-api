<?php

use App\Enums\Routine\RoutineStatus;
use App\Jobs\Cycle\GenerateCycleJob;
use App\Models\AthleteProfile;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function routinePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Winter Volume',
        'goal' => 'hypertrophy',
        'hint' => 'PPL split, dumbbells only',
    ], $overrides);
}

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    Bus::fake();
    $this->user = User::factory()->create();
    AthleteProfile::factory()->for($this->user)->create();
});

// TC-1
it('creates the first routine active and queues cycle generation', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload());

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Winter Volume')
        ->assertJsonPath('data.goal', 'hypertrophy')
        ->assertJsonPath('data.hint', 'PPL split, dumbbells only')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.days_per_cycle', 5)
        ->assertJsonPath('data.archived_at', null);

    expect($response->json('data.id'))->toMatch(uuidV4Pattern());

    $this->assertDatabaseCount('routines', 1);
    $this->assertDatabaseHas('routines', [
        'user_id' => $this->user->id,
        'name' => 'Winter Volume',
        'goal' => 'hypertrophy',
        'status' => 'active',
        'days_per_cycle' => 5,
    ]);

    Bus::assertDispatched(
        GenerateCycleJob::class,
        fn (GenerateCycleJob $job): bool => $job->routine->user_id === $this->user->id,
    );
});

// TC-2
it('archives the previous active routine, permanently', function () {
    $previous = Routine::factory()->for($this->user)->create();

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.archived_at', null);

    expect($previous->refresh()->status)->toBe(RoutineStatus::Archived)
        ->and($previous->archived_at)->not->toBeNull();

    $this->assertDatabaseCount('routines', 2);
    expect($this->user->routines()->where('status', RoutineStatus::Active)->count())->toBe(1);
});

// TC-3
it('creates the routine active with a null archived_at when the user had no routine', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.archived_at', null);

    $this->assertDatabaseHas('routines', [
        'user_id' => $this->user->id,
        'status' => 'active',
        'archived_at' => null,
    ]);
});

// TC-4 / TC-5
it('requires name and goal', function (string $field) {
    $payload = routinePayload();
    unset($payload[$field]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field, 'data.errors');

    $this->assertDatabaseCount('routines', 0);
    Bus::assertNothingDispatched();
})->with(['name', 'goal']);

// TC-6
it('rejects a goal outside the allowed set', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['goal' => 'powerlifting']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('goal', 'data.errors');

    $this->assertDatabaseCount('routines', 0);
});

// TC-7
it('accepts every valid goal value', function (string $value) {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['goal' => $value]))
        ->assertCreated()
        ->assertJsonPath('data.goal', $value);
})->with(['hypertrophy', 'strength', 'fat_loss', 'general_health', 'endurance']);

// TC-8
it('enforces the name length boundary', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines', routinePayload(['name' => str_repeat('a', 255)]))
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson('/api/v1/routines', routinePayload(['name' => str_repeat('a', 256)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name', 'data.errors');
});

// TC-9
it('saves an omitted hint as null', function () {
    $payload = routinePayload();
    unset($payload['hint']);

    $this->actingAs($this->user)->postJson('/api/v1/routines', $payload)
        ->assertCreated()
        ->assertJsonPath('data.hint', null);

    $this->assertDatabaseHas('routines', [
        'user_id' => $this->user->id,
        'hint' => null,
    ]);
});

// TC-10
it('saves an empty or whitespace-only hint as null', function (string $value) {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['hint' => $value]))
        ->assertCreated()
        ->assertJsonPath('data.hint', null);

    $this->assertDatabaseHas('routines', ['hint' => null]);
})->with(['empty' => [''], 'whitespace' => ['   ']]);

// TC-11
it('enforces the hint length boundary', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines', routinePayload(['hint' => str_repeat('a', 2000)]))
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson('/api/v1/routines', routinePayload(['hint' => str_repeat('a', 2001)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('hint', 'data.errors');
});

// TC-12
it('ignores days_per_cycle sent in the request', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines', routinePayload(['days_per_cycle' => 3]))
        ->assertCreated()
        ->assertJsonPath('data.days_per_cycle', 5);

    $this->assertDatabaseHas('routines', [
        'user_id' => $this->user->id,
        'days_per_cycle' => 5,
    ]);
});

// TC-13
it('rejects a user who has not completed onboarding', function () {
    $noProfile = User::factory()->create();

    $this->actingAs($noProfile)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'PROFILE_INCOMPLETE')
        ->assertJsonPath('data.message', 'Complete your athlete profile before creating a routine.')
        ->assertJsonMissingPath('data.errors');

    $this->assertDatabaseCount('routines', 0);
    Bus::assertNothingDispatched();
});

// TC-14
it('rejects an unauthenticated request', function () {
    $this->postJson('/api/v1/routines', routinePayload())
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');

    $this->assertDatabaseCount('routines', 0);
    Bus::assertNothingDispatched();
});

// TC-15
it('never touches another users routine', function () {
    $other = User::factory()->create();
    AthleteProfile::factory()->for($other)->create();
    $otherRoutine = Routine::factory()->for($other)->create();

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated();

    expect($otherRoutine->refresh()->status)->toBe(RoutineStatus::Active)
        ->and($otherRoutine->archived_at)->toBeNull();

    $this->assertDatabaseCount('routines', 2);
    expect($other->routines()->where('status', RoutineStatus::Active)->count())->toBe(1)
        ->and($this->user->routines()->where('status', RoutineStatus::Active)->count())->toBe(1);
});

// TC-16
it('exposes the uuid as id, never the internal primary key', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated();

    $routine = Routine::query()->firstOrFail();

    expect($response->json('data.id'))->toBe($routine->uuid)
        ->and($response->json('data.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.id'))->not->toBe((string) $routine->id);

    $response
        ->assertJsonMissingPath('data.user_id')
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'goal', 'hint', 'days_per_cycle',
                'status', 'archived_at', 'created_at', 'updated_at',
            ],
        ]);
});

// TC-17
it('serialises enums as strings and dates as ISO-8601', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated();

    expect($response->json('data.status'))->toBe('active')
        ->and($response->json('data.goal'))->toBe('hypertrophy')
        ->and($response->json('data.created_at'))->toMatch(iso8601Pattern());
});

// TC-18
it('renders without triggering a lazy load under strict mode', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated()
        ->assertJsonMissingPath('data.user');
});
