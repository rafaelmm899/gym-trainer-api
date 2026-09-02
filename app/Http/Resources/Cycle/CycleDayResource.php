<?php

namespace App\Http\Resources\Cycle;

use App\Models\CycleDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CycleDay
 */
class CycleDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'order' => $this->order,
            'label' => $this->label,
            'focus_muscle_groups' => $this->focus_muscle_groups,
            'rationale' => $this->rationale,
            'exercises' => DayExerciseResource::collection($this->whenLoaded('dayExercises')),
        ];
    }
}
