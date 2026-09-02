<?php

namespace App\Http\Controllers\Profile;

use App\Http\Requests\Profile\ShowAthleteProfileRequest;
use App\Http\Resources\Profile\AthleteProfileStatusResource;
use App\Models\User;

final class ShowAthleteProfileController
{
    public function __invoke(ShowAthleteProfileRequest $request): AthleteProfileStatusResource
    {
        /** @var User $user */
        $user = $request->user();

        return AthleteProfileStatusResource::make($user->athleteProfile()->first());
    }
}
