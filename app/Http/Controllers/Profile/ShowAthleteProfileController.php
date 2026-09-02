<?php

namespace App\Http\Controllers\Profile;

use App\Http\Resources\Profile\AthleteProfileStatusResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ShowAthleteProfileController
{
    public function __invoke(Request $request): AthleteProfileStatusResource
    {
        /** @var User $user */
        $user = $request->user();

        return AthleteProfileStatusResource::make($user->athleteProfile()->first());
    }
}
