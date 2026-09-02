<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UserLogoutAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController
{
    public function __invoke(Request $request, UserLogoutAction $action): Response
    {
        $action->handle();

        return response()->noContent();
    }
}
