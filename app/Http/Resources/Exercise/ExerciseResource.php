<?php

namespace App\Http\Resources\Exercise;

use App\Models\Exercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An exercise of the catalogue, without its instructions: it is also nested in
 * recommendations and set logs, which must stay small. The instructions are in
 * {@see ExerciseDetailResource}.
 *
 * @mixin Exercise
 */
class ExerciseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'primary_muscle_group' => $this->primary_muscle_group?->value,
            'equipment' => $this->equipment,
            'target_muscle' => $this->target_muscle,
            'secondary_muscles' => $this->secondary_muscles ?? [],
            'media_url' => $this->media_url,
        ];
    }
}
