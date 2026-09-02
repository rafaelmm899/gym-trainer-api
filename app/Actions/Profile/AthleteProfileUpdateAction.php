<?php

namespace App\Actions\Profile;

use App\Data\Profile\AthleteProfileData;
use App\Models\AthleteProfile;
use App\Models\User;

final class AthleteProfileUpdateAction
{
    public function handle(User $user, AthleteProfileData $data): AthleteProfile
    {
        return $user->athleteProfile()->updateOrCreate([], [
            'experience_level' => $data->experienceLevel,
            'days_per_week' => $data->daysPerWeek,
            'session_minutes' => $data->sessionMinutes,
            'goal' => $data->goal,
            'notes' => $data->notes,
        ]);
    }
}
