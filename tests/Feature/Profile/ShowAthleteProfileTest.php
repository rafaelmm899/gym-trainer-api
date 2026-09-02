<?php

use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Models\AthleteProfile;
use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

// TC-18
it('returns the saved profile', function () {
    AthleteProfile::factory()->for($this->user)->create([
        'experience_level' => ExperienceLevel::Advanced,
        'days_per_week' => 5,
        'session_minutes' => 75,
        'goal' => Goal::Strength,
        'notes' => 'prefers barbell',
    ]);

    $this->actingAs($this->user)->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.onboarding_completed', true)
        ->assertJsonPath('data.profile.experience_level', 'advanced')
        ->assertJsonPath('data.profile.days_per_week', 5)
        ->assertJsonPath('data.profile.session_minutes', 75)
        ->assertJsonPath('data.profile.goal', 'strength')
        ->assertJsonPath('data.profile.notes', 'prefers barbell');
});

// TC-19
it('signals onboarding pending when no profile exists', function () {
    $this->actingAs($this->user)->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.onboarding_completed', false)
        ->assertJsonPath('data.profile', null);
});

// TC-20
it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
});

// TC-21
it('only ever returns the acting users profile', function () {
    AthleteProfile::factory()->for($this->user)->create([
        'experience_level' => ExperienceLevel::Beginner,
        'goal' => Goal::FatLoss,
        'days_per_week' => 2,
    ]);

    $other = User::factory()->create();
    AthleteProfile::factory()->for($other)->create([
        'experience_level' => ExperienceLevel::Advanced,
        'goal' => Goal::Strength,
        'days_per_week' => 6,
    ]);

    $this->actingAs($this->user)->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.profile.experience_level', 'beginner')
        ->assertJsonPath('data.profile.goal', 'fat_loss')
        ->assertJsonPath('data.profile.days_per_week', 2);
});

// TC-22
it('never exposes the internal id', function () {
    AthleteProfile::factory()->for($this->user)->create();

    $this->actingAs($this->user)->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonMissingPath('data.profile.id')
        ->assertJsonStructure([
            'data' => [
                'onboarding_completed',
                'profile' => [
                    'experience_level',
                    'days_per_week',
                    'session_minutes',
                    'goal',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
});
