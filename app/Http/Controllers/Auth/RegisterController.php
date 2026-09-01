<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UserRegisterAction;
use App\Data\Auth\RegisterData;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class RegisterController
{
    public function __invoke(RegisterRequest $request, UserRegisterAction $action): JsonResponse
    {
        $user = $action->handle(RegisterData::from($request->validated()));

        return UserResource::make($user)->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
