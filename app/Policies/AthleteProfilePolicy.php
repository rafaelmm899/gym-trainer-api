<?php

namespace App\Policies;

use App\Models\AthleteProfile;
use App\Models\User;

final class AthleteProfilePolicy
{
    public function view(User $user, AthleteProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AthleteProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
