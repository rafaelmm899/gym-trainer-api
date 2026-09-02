<?php

namespace App\Http\Resources\Routine;

use App\Http\Resources\Cycle\CycleResource;
use App\Models\Routine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Routine
 */
class RoutineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'goal' => $this->goal->value,
            'hint' => $this->hint,
            'days_per_cycle' => $this->days_per_cycle,
            'status' => $this->status->value,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'cycle' => CycleResource::make($this->whenLoaded('cycle')),
        ];
    }
}
