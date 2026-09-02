<?php

use App\Models\Routine;
use App\Models\User;
use App\Policies\RoutinePolicy;

// TC-22
it('lets any user create a routine', function () {
    expect((new RoutinePolicy)->create(new User))->toBeTrue();
});

// TC-15
it('lets the owner view their routine', function () {
    $user = new User;
    $user->id = 1;

    $routine = new Routine;
    $routine->user_id = 1;

    expect((new RoutinePolicy)->view($user, $routine))->toBeTrue();
});

// TC-16
it('denies viewing another users routine', function () {
    $user = new User;
    $user->id = 2;

    $routine = new Routine;
    $routine->user_id = 1;

    expect((new RoutinePolicy)->view($user, $routine))->toBeFalse();
});
