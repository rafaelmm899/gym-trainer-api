<?php

use App\Data\Profile\AthleteProfileData;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;

// TC-24
it('maps snake_case input, casts enums and defaults notes to null', function () {
    $data = AthleteProfileData::from([
        'experience_level' => 'beginner',
        'days_per_week' => 3,
        'session_minutes' => 45,
        'goal' => 'strength',
    ]);

    expect($data->experienceLevel)->toBe(ExperienceLevel::Beginner)
        ->and($data->goal)->toBe(Goal::Strength)
        ->and($data->daysPerWeek)->toBe(3)
        ->and($data->sessionMinutes)->toBe(45)
        ->and($data->notes)->toBeNull();
});
