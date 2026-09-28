<?php

use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use App\Policies\TrainingSessionPolicy;

// TC-27
it('lets the owner open a session under their routine', function () {
    $owner = new User;
    $owner->id = 1;

    $routine = new Routine;
    $routine->user_id = 1;

    expect((new TrainingSessionPolicy)->create($owner, $routine))->toBeTrue();
});

// TC-27
it('denies opening a session under another users routine', function () {
    $stranger = new User;
    $stranger->id = 2;

    $routine = new Routine;
    $routine->user_id = 1;

    expect((new TrainingSessionPolicy)->create($stranger, $routine))->toBeFalse();
});

// TC-18
it('lets only the owner view a session', function () {
    $owner = new User;
    $owner->id = 1;
    $stranger = new User;
    $stranger->id = 2;

    $session = new TrainingSession;
    $session->user_id = 1;

    expect((new TrainingSessionPolicy)->view($owner, $session))->toBeTrue()
        ->and((new TrainingSessionPolicy)->view($stranger, $session))->toBeFalse();
});
