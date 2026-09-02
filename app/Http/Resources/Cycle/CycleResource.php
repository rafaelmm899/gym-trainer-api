<?php

namespace App\Http\Resources\Cycle;

use App\Models\Cycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cycle
 */
class CycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'sequence_number' => $this->sequence_number,
            'status' => $this->status->value,
            'split_rationale' => $this->split_rationale,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'days' => CycleDayResource::collection($this->whenLoaded('cycleDays')),
        ];
    }
}
