<?php

namespace App\Http\Resources\Session;

use App\Http\Resources\Cycle\CycleDayResource;
use App\Models\TrainingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrainingSession
 */
class TrainingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'analysis_state' => $this->analysis_state->value,
            'note' => $this->note,
            'perceived_effort' => $this->perceived_effort,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'cycle_day' => CycleDayResource::make($this->whenLoaded('cycleDay')),
        ];
    }
}
