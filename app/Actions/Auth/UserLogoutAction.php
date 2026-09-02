<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Auth;

final class UserLogoutAction
{
    public function handle(): void
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
