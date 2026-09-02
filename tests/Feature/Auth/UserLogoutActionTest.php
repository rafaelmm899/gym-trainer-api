<?php

use App\Actions\Auth\UserLogoutAction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    // The Action invalidates the session, so bind one to the current request.
    $this->app['request']->setLaravelSession($this->app->make('session.store'));
});

// TC-20
it('clears authentication', function () {
    $user = User::factory()->create();
    Auth::login($user);

    expect(Auth::check())->toBeTrue();

    app(UserLogoutAction::class)->handle();

    expect(Auth::check())->toBeFalse();
});
