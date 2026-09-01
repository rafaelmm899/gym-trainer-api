<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ], $overrides);
}

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
});

// TC-1
it('registers a new user and authenticates them', function () {
    $response = $this->postJson('/api/v1/register', registerPayload());

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Ada Lovelace')
        ->assertJsonPath('data.email', 'ada@example.com');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);

    $user = User::firstOrFail();
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

// TC-2
it('sets the session cookie on the response', function () {
    $this->postJson('/api/v1/register', registerPayload())
        ->assertCreated()
        ->assertCookie(config('session.cookie'));
});

// TC-3
it('normalises the email to lowercase and trims it', function () {
    $this->postJson('/api/v1/register', registerPayload(['email' => '  Ada@Example.COM  ']))
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.com');

    $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
});

// TC-4
it('rejects an already registered email with a 422 on the email field', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/register', registerPayload(['email' => 'taken@example.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('users', 1);
    $this->assertGuest();
});

// TC-5
it('rejects an already registered email regardless of casing', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/register', registerPayload(['email' => 'TAKEN@example.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('users', 1);
});

// TC-6
it('rejects a password that does not match its confirmation', function () {
    $this->postJson('/api/v1/register', registerPayload([
        'password' => 'secret-password',
        'password_confirmation' => 'other-password',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    $this->assertDatabaseCount('users', 0);
    $this->assertGuest();
});

// TC-7
it('rejects a password shorter than the minimum length', function () {
    $this->postJson('/api/v1/register', registerPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    $this->assertGuest();
});

// TC-8
it('requires a name', function () {
    $payload = registerPayload();
    unset($payload['name']);

    $this->postJson('/api/v1/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// TC-9
it('requires a valid email', function (mixed $email) {
    $payload = registerPayload();
    $payload['email'] = $email;

    $this->postJson('/api/v1/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
})->with([
    'missing' => [null],
    'malformed' => ['not-an-email'],
]);

// TC-10
it('requires a password', function () {
    $payload = registerPayload();
    unset($payload['password'], $payload['password_confirmation']);

    $this->postJson('/api/v1/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

// TC-11
it('does not expose the internal id', function () {
    $this->postJson('/api/v1/register', registerPayload())
        ->assertCreated()
        ->assertJsonMissingPath('data.id')
        ->assertJsonStructure(['data' => ['name', 'email', 'created_at']]);
});

// TC-12
it('creates a user with no athlete profile and no routines', function () {
    // AC #4 holds by construction: registration writes only the users row.
    // This gains real assertions ($user->athleteProfile()->exists() === false,
    // $user->routines()->count() === 0) once the Profile / Routine domains exist.
    $this->postJson('/api/v1/register', registerPayload())
        ->assertCreated()
        ->assertJsonMissingPath('data.profile')
        ->assertJsonMissingPath('data.routines');

    $this->assertDatabaseCount('users', 1);
});

// TC-13
it('rate limits the registration route to 6 requests per minute', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $payload = registerPayload(['email' => 'taken@example.com']);

    for ($i = 1; $i <= 6; $i++) {
        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    $this->postJson('/api/v1/register', $payload)->assertStatus(429);
});

// TC-14
it('serves the csrf-cookie handshake for an allowed origin', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});
