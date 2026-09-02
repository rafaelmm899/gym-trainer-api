<?php

use App\Actions\Profile\AthleteProfileUpdateAction;
use App\Data\Profile\AthleteProfileData;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Models\User;

// TC-23
it('upserts exactly one row and maps the DTO to columns', function () {
    $user = User::factory()->create();
    $action = app(AthleteProfileUpdateAction::class);

    $created = $action->handle($user, new AthleteProfileData(
        experienceLevel: ExperienceLevel::Beginner,
        daysPerWeek: 3,
        sessionMinutes: 45,
        goal: Goal::FatLoss,
        notes: null,
    ));

    expect($created->wasRecentlyCreated)->toBeTrue();
    $this->assertDatabaseCount('athlete_profiles', 1);
    $this->assertDatabaseHas('athlete_profiles', [
        'user_id' => $user->id,
        'experience_level' => 'beginner',
        'days_per_week' => 3,
    ]);

    $updated = $action->handle($user, new AthleteProfileData(
        experienceLevel: ExperienceLevel::Beginner,
        daysPerWeek: 5,
        sessionMinutes: 45,
        goal: Goal::FatLoss,
        notes: null,
    ));

    expect($updated->wasRecentlyCreated)->toBeFalse()
        ->and($updated->id)->toBe($created->id);
    $this->assertDatabaseCount('athlete_profiles', 1);
    $this->assertDatabaseHas('athlete_profiles', [
        'user_id' => $user->id,
        'days_per_week' => 5,
    ]);
});
