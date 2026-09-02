<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Exceptions\StubDomainException;

beforeEach(function () {
    Route::get('/api/v1/_test/domain', fn () => throw new StubDomainException('This routine is archived.'));
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('internal detail'));
    Route::get('/_test/boom-web', fn () => throw new RuntimeException('internal detail'));
});

it('returns the envelope for an unauthenticated protected endpoint', function () {
    $this->getJson('/api/v1/user')
        ->assertStatus(401)
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')
        ->assertJsonMissingPath('data.errors');
});

it('returns the envelope for wrong login credentials with no field errors', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(401)
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')
        ->assertJsonPath('data.message', 'These credentials do not match our records.')
        ->assertJsonMissingPath('data.errors');
});

it('keeps message and errors on a validation failure and adds a code, all under data', function () {
    $this->postJson('/api/v1/register', [])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION')
        ->assertJsonValidationErrors(['name', 'email', 'password'], 'data.errors')
        ->assertJsonStructure(['data' => ['code', 'message', 'errors']]);
});

it('returns the envelope for an unknown api path', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')
        ->assertJsonPath('data.message', 'Resource not found.');
});

it('renders a thrown DomainException end to end', function () {
    $this->getJson('/api/v1/_test/domain')
        ->assertStatus(409)
        ->assertExactJson(['data' => ['code' => 'DOMAIN_EXCEPTION', 'message' => 'This routine is archived.']]);
});

it('masks an unhandled throwable on an api route when app.debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('data.code', 'SERVER_EXCEPTION')
        ->assertJsonPath('data.message', 'Server error.')
        ->assertJsonMissingPath('data.trace');

    expect($response->getContent())->not->toContain('internal detail');
});

it('still wraps an unhandled throwable in data when app.debug is on', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('data.code', 'SERVER_EXCEPTION')
        ->assertJsonPath('data.message', 'internal detail')
        ->assertJsonStructure(['data' => ['code', 'message', 'exception', 'file', 'line', 'trace']])
        ->assertJsonMissingPath('message')
        ->assertJsonMissingPath('exception');
});

it('leaves a non-json, non-api request to Laravel', function () {
    config(['app.debug' => false]);

    $response = $this->get('/_test/boom-web', ['Accept' => 'text/html']);

    $response->assertStatus(500);
    expect($response->headers->get('content-type'))->not->toContain('application/json');
});
