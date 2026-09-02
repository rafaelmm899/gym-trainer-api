<?php

use App\Models\AthleteProfile;
use App\Models\User;
use App\Policies\AthleteProfilePolicy;

// TC-25
it('grants view and update only to the profile owner', function () {
    $owner = tap(new User)->forceFill(['id' => 1]);
    $stranger = tap(new User)->forceFill(['id' => 2]);
    $profile = tap(new AthleteProfile)->forceFill(['user_id' => 1]);

    $policy = new AthleteProfilePolicy;

    expect($policy->view($owner, $profile))->toBeTrue()
        ->and($policy->update($owner, $profile))->toBeTrue()
        ->and($policy->view($stranger, $profile))->toBeFalse()
        ->and($policy->update($stranger, $profile))->toBeFalse()
        ->and($policy->create($stranger))->toBeTrue();
});
