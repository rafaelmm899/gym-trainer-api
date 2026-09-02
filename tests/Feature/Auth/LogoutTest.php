<?php

use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
});

// TC-13
it('logs out an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/logout')
        ->assertNoContent();

    // Sanctum SPA auth rides the web session guard; the action clears it.
    $this->assertGuest('web');
});

// TC-14
it('rejects logout without an active session', function () {
    $this->postJson('/api/v1/logout')
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

// TC-15
it('invalidates the session so the user endpoint rejects it afterwards', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();

    $this->postJson('/api/v1/logout')->assertNoContent();

    // Drop the in-process guard singletons so the next call resolves auth from
    // scratch, the way an independent HTTP request would (a real client would
    // send its now-dead session cookie and get the same 401).
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/user')->assertStatus(401);
});
