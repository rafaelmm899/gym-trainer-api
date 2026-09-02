<?php

use App\Models\User;
use App\Policies\RoutinePolicy;

// TC-22
it('lets any user create a routine', function () {
    expect((new RoutinePolicy)->create(new User))->toBeTrue();
});
