<?php

use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
});

// TC-1
it('authenticates a user with valid credentials', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.name', $user->name);

    $this->assertAuthenticatedAs($user);
});

// TC-2
it('sets the session cookie on the response', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertCookie(config('session.cookie'));
});

// TC-3
it('normalises the email before checking credentials', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/login', ['email' => '  Ada@Example.COM  ', 'password' => 'password'])
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});

// TC-4
it('rejects a wrong password with a generic 401 and no session', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
        ->assertStatus(401)
        ->assertExactJson(['data' => ['code' => 'AUTHENTICATION_EXCEPTION', 'message' => 'These credentials do not match our records.']]);

    $this->assertGuest();
});

// TC-5
it('rejects an unknown email with the same generic 401', function () {
    $this->postJson('/api/v1/login', ['email' => 'nobody@example.com', 'password' => 'password'])
        ->assertStatus(401)
        ->assertExactJson(['data' => ['code' => 'AUTHENTICATION_EXCEPTION', 'message' => 'These credentials do not match our records.']])
        ->assertJsonMissingPath('data.errors');

    $this->assertGuest();
});

// TC-6
it('requires an email', function () {
    $this->postJson('/api/v1/login', ['password' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email', 'data.errors');
});

// TC-7
it('requires a password', function () {
    $this->postJson('/api/v1/login', ['email' => 'ada@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password', 'data.errors');
});

// TC-8
it('requires a valid email', function (mixed $email) {
    $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email', 'data.errors');
})->with([
    'malformed' => ['not-an-email'],
    'non-string' => [123],
]);

// TC-9
it('re-authenticates when a session is already active', function () {
    $userA = User::factory()->create(['email' => 'a@example.com']);
    $userB = User::factory()->create(['email' => 'b@example.com']);

    $this->actingAs($userA)
        ->postJson('/api/v1/login', ['email' => 'b@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'b@example.com');

    $this->assertAuthenticatedAs($userB);
});

// TC-10
it('does not expose the internal id', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonMissingPath('data.id')
        ->assertJsonStructure(['data' => ['name', 'email', 'created_at']]);
});

// TC-11
it('rate limits the login route to 6 requests per minute', function () {
    User::factory()->create(['email' => 'ada@example.com']);
    $payload = ['email' => 'ada@example.com', 'password' => 'wrong-password'];

    for ($i = 1; $i <= 6; $i++) {
        $this->postJson('/api/v1/login', $payload)->assertStatus(401);
    }

    $this->postJson('/api/v1/login', $payload)->assertStatus(429);
});

// TC-12
it('completes a login after the csrf-cookie handshake', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});
