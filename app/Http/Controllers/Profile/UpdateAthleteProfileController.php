<?php

namespace App\Http\Controllers\Profile;

use App\Actions\Profile\AthleteProfileUpdateAction;
use App\Data\Profile\AthleteProfileData;
use App\Http\Requests\Profile\UpdateAthleteProfileRequest;
use App\Http\Resources\Profile\AthleteProfileStatusResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class UpdateAthleteProfileController
{
    public function __invoke(UpdateAthleteProfileRequest $request, AthleteProfileUpdateAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = $action->handle($user, AthleteProfileData::from($request->validated()));

        return AthleteProfileStatusResource::make($profile)
            ->response()
            ->setStatusCode($profile->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
