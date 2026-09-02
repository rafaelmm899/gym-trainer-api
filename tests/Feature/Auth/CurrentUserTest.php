<?php

use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
});

// TC-16
it('returns the authenticated user identity', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonMissingPath('data.id')
        ->assertJsonStructure(['data' => ['name', 'email', 'created_at']]);
});

// TC-17
it('requires a session', function () {
    $this->getJson('/api/v1/user')
        ->assertStatus(401)
        ->assertExactJson(['data' => ['code' => 'AUTHENTICATION_EXCEPTION', 'message' => 'Unauthenticated.']]);
});
