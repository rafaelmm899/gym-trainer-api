<?php

namespace App\Http\Controllers\Auth;

use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class CurrentUserController
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user);
    }
}
