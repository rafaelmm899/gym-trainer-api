<?php

use App\Models\AthleteProfile;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function profilePayload(array $overrides = []): array
{
    return array_merge([
        'experience_level' => 'intermediate',
        'days_per_week' => 4,
        'session_minutes' => 60,
        'goal' => 'hypertrophy',
        'notes' => 'bad left knee',
    ], $overrides);
}

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

// TC-1
it('creates the profile on the first save', function () {
    $response = $this->actingAs($this->user)->putJson('/api/v1/profile', profilePayload());

    $response->assertCreated()
        ->assertJsonPath('data.onboarding_completed', true)
        ->assertJsonPath('data.profile.experience_level', 'intermediate')
        ->assertJsonPath('data.profile.days_per_week', 4)
        ->assertJsonPath('data.profile.session_minutes', 60)
        ->assertJsonPath('data.profile.goal', 'hypertrophy')
        ->assertJsonPath('data.profile.notes', 'bad left knee');

    $this->assertDatabaseCount('athlete_profiles', 1);
    $this->assertDatabaseHas('athlete_profiles', [
        'user_id' => $this->user->id,
        'experience_level' => 'intermediate',
        'goal' => 'hypertrophy',
        'days_per_week' => 4,
        'session_minutes' => 60,
    ]);
});

// TC-2
it('updates the same row on a second save and never creates a second', function () {
    $profile = AthleteProfile::factory()->for($this->user)->create(['days_per_week' => 3]);

    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['days_per_week' => 6]))
        ->assertOk()
        ->assertJsonPath('data.onboarding_completed', true);

    $this->assertDatabaseCount('athlete_profiles', 1);
    $this->assertDatabaseHas('athlete_profiles', [
        'id' => $profile->id,
        'user_id' => $this->user->id,
        'days_per_week' => 6,
    ]);
});

// TC-3
it('requires every non-optional field', function (string $field) {
    $payload = profilePayload();
    unset($payload[$field]);

    $this->actingAs($this->user)->putJson('/api/v1/profile', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field, 'data.errors');

    $this->assertDatabaseCount('athlete_profiles', 0);
})->with(['experience_level', 'days_per_week', 'session_minutes', 'goal']);

// TC-4
it('rejects an experience_level outside the allowed set', function () {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['experience_level' => 'expert']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('experience_level', 'data.errors');

    $this->assertDatabaseCount('athlete_profiles', 0);
});

// TC-5
it('rejects a goal outside the allowed set', function () {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['goal' => 'powerlifting']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('goal', 'data.errors');
});

// TC-6
it('accepts every valid experience_level', function (string $value) {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['experience_level' => $value]))
        ->assertCreated()
        ->assertJsonPath('data.profile.experience_level', $value);
})->with(['beginner', 'intermediate', 'advanced']);

// TC-7
it('accepts every valid goal', function (string $value) {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['goal' => $value]))
        ->assertCreated()
        ->assertJsonPath('data.profile.goal', $value);
})->with(['hypertrophy', 'strength', 'fat_loss', 'general_health', 'endurance']);

// TC-8
it('rejects an out-of-range or non-integer days_per_week', function (mixed $value) {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['days_per_week' => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('days_per_week', 'data.errors');

    $this->assertDatabaseCount('athlete_profiles', 0);
})->with([0, 8, -1, 3.5, 'abc']);

// TC-9
it('rejects an out-of-range or non-integer session_minutes', function (mixed $value) {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['session_minutes' => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('session_minutes', 'data.errors');
})->with([9, 241, 0, 'x']);

// TC-10
it('saves an omitted notes as null', function () {
    $payload = profilePayload();
    unset($payload['notes']);

    $this->actingAs($this->user)->putJson('/api/v1/profile', $payload)
        ->assertCreated()
        ->assertJsonPath('data.profile.notes', null);

    $this->assertDatabaseHas('athlete_profiles', [
        'user_id' => $this->user->id,
        'notes' => null,
    ]);
});

// TC-11
it('saves an empty or whitespace-only notes as null', function (string $value) {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['notes' => $value]))
        ->assertCreated()
        ->assertJsonPath('data.profile.notes', null);

    $this->assertDatabaseHas('athlete_profiles', ['notes' => null]);
})->with(['empty' => [''], 'whitespace' => ['   ']]);

// TC-12
it('enforces the notes length boundary', function () {
    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['notes' => str_repeat('a', 2000)]))
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload(['notes' => str_repeat('a', 2001)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('notes', 'data.errors');
});

// TC-13
it('rejects an unauthenticated request', function () {
    $this->putJson('/api/v1/profile', profilePayload())
        ->assertUnauthorized();

    $this->assertDatabaseCount('athlete_profiles', 0);
});

// TC-14
it('never touches another users row', function () {
    $other = User::factory()->has(AthleteProfile::factory())->create();
    $otherProfile = $other->athleteProfile()->firstOrFail();

    $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload())
        ->assertCreated();

    $this->assertDatabaseCount('athlete_profiles', 2);
    $this->assertDatabaseHas('athlete_profiles', [
        'id' => $otherProfile->id,
        'user_id' => $other->id,
        'experience_level' => $otherProfile->experience_level->value,
    ]);
    expect(
        AthleteProfile::where('user_id', $this->user->id)->value('user_id')
    )->toBe($this->user->id);
});

// TC-15
it('never exposes the internal id', function () {
    $this->actingAs($this->user)->putJson('/api/v1/profile', profilePayload())
        ->assertCreated()
        ->assertJsonMissingPath('data.profile.id')
        ->assertJsonStructure([
            'data' => [
                'onboarding_completed',
                'profile' => [
                    'experience_level',
                    'days_per_week',
                    'session_minutes',
                    'goal',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
});

// TC-16
it('serialises enums as strings and dates as ISO-8601', function () {
    $response = $this->actingAs($this->user)
        ->putJson('/api/v1/profile', profilePayload())
        ->assertCreated();

    expect($response->json('data.profile.experience_level'))->toBe('intermediate')
        ->and($response->json('data.profile.goal'))->toBe('hypertrophy')
        ->and($response->json('data.profile.created_at'))
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

// TC-17
it('renders without triggering a lazy load under strict mode', function () {
    $this->actingAs($this->user)->putJson('/api/v1/profile', profilePayload())
        ->assertSuccessful()
        ->assertJsonMissingPath('data.profile.user');

    $this->actingAs($this->user)->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonMissingPath('data.profile.user');
});
