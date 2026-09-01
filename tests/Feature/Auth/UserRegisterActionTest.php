<?php

use App\Actions\Auth\UserRegisterAction;
use App\Data\Auth\RegisterData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

it('creates one user, hashes the password and starts a session', function () {
    // The Action regenerates the session, so bind one to the current request.
    $this->app['request']->setLaravelSession($this->app->make('session.store'));

    $user = app(UserRegisterAction::class)->handle(new RegisterData(
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        password: 'secret-password',
    ));

    expect($user->wasRecentlyCreated)->toBeTrue();
    $this->assertDatabaseCount('users', 1);
    expect($user->password)->not->toBe('secret-password')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id);
});
