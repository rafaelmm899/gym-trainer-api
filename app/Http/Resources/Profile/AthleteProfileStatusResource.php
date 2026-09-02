<?php

namespace App\Http\Resources\Profile;

use App\Models\AthleteProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AthleteProfileStatusResource extends JsonResource
{
    public function __construct(?AthleteProfile $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AthleteProfile|null $profile */
        $profile = $this->resource;

        return [
            'onboarding_completed' => $profile !== null,
            'profile' => $profile === null
                ? null
                : (new AthleteProfileResource($profile))->toArray($request),
        ];
    }
}
