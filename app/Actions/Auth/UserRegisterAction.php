<?php

namespace App\Actions\Auth;

use App\Data\Auth\RegisterData;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class UserRegisterAction
{
    public function handle(RegisterData $data): User
    {
        $user = User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
        ]);

        Auth::login($user);
        request()->session()->regenerate();

        return $user;
    }
}
