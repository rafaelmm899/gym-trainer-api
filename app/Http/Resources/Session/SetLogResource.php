<?php

namespace App\Http\Resources\Session;

use App\Http\Resources\Exercise\ExerciseResource;
use App\Models\SetLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SetLog
 */
class SetLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'exercise' => ExerciseResource::make($this->whenLoaded('exercise')),
            'set_number' => $this->set_number,
            'weight_kg' => (float) $this->weight_kg,
            'reps' => $this->reps,
            'rpe' => $this->rpe !== null ? (float) $this->rpe : null,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
