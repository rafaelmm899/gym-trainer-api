<?php

use App\Models\TrainingSession;
use App\Models\User;
use App\Policies\SetLogPolicy;

// TC-48
it('lets the session owner log a set and denies a stranger', function () {
    $owner = new User;
    $owner->id = 1;

    $stranger = new User;
    $stranger->id = 2;

    $session = new TrainingSession;
    $session->user_id = 1;

    expect((new SetLogPolicy)->create($owner, $session))->toBeTrue()
        ->and((new SetLogPolicy)->create($stranger, $session))->toBeFalse();
});

// TC-49
it('lets the session owner correct a set and denies a stranger', function () {
    $owner = new User;
    $owner->id = 1;

    $stranger = new User;
    $stranger->id = 2;

    $session = new TrainingSession;
    $session->user_id = 1;

    expect((new SetLogPolicy)->update($owner, $session))->toBeTrue()
        ->and((new SetLogPolicy)->update($stranger, $session))->toBeFalse();
});
