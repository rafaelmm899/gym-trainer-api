<?php

namespace App\Http\Resources\Profile;

use App\Models\AthleteProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AthleteProfile
 */
class AthleteProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'experience_level' => $this->experience_level->value,
            'days_per_week' => $this->days_per_week,
            'session_minutes' => $this->session_minutes,
            'goal' => $this->goal->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
