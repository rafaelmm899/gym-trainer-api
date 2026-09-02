<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UserLoginAction;
use App\Data\Auth\LoginData;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\UserResource;

final class LoginController
{
    public function __invoke(LoginRequest $request, UserLoginAction $action): UserResource
    {
        $user = $action->handle(LoginData::from($request->validated()));

        return UserResource::make($user);
    }
}
