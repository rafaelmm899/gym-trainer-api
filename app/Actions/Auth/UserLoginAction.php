<?php

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

final class UserLoginAction
{
    /**
     * @throws AuthenticationException
     */
    public function handle(LoginData $data): User
    {
        throw_unless(
            Auth::attempt(['email' => $data->email, 'password' => $data->password]),
            new AuthenticationException('These credentials do not match our records.'),
        );

        request()->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
