<?php

use App\Actions\Auth\UserLoginAction;
use App\Data\Auth\LoginData;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    // The Action regenerates the session, so bind one to the current request.
    $this->app['request']->setLaravelSession($this->app->make('session.store'));
});

// TC-18
it('authenticates the user when the credentials are correct', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $result = app(UserLoginAction::class)->handle(new LoginData(
        email: 'ada@example.com',
        password: 'password',
    ));

    expect($result->is($user))->toBeTrue()
        ->and(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id);
});

// TC-19
it('throws a generic authentication exception for a bad password', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    expect(fn () => app(UserLoginAction::class)->handle(new LoginData(
        email: 'ada@example.com',
        password: 'nope',
    )))->toThrow(AuthenticationException::class, 'These credentials do not match our records.');

    expect(Auth::check())->toBeFalse();
});
