<?php

namespace App\Http\Resources\Cycle;

use App\Models\DayExercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin DayExercise
 */
class DayExerciseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'order' => $this->order,
            'name' => $this->exercise->name,
            'sets' => $this->sets,
            'rep_min' => $this->rep_min,
            'rep_max' => $this->rep_max,
            'target_weight_kg' => (float) $this->target_weight_kg,
            'target_rpe' => $this->target_rpe !== null ? (float) $this->target_rpe : null,
            'rest_seconds' => $this->rest_seconds,
            'rationale' => $this->rationale,
            'previous' => $this->whenLoaded('previous', fn (): array => [
                'session_id' => $this->previous->sessionId,
                'started_at' => $this->previous->startedAt->toIso8601String(),
                'cycle_sequence_number' => $this->previous->cycleSequenceNumber,
                'sets' => array_map(
                    fn (array $set): array => Arr::only($set, ['set_number', 'weight_kg', 'reps', 'rpe']),
                    $this->previous->sets,
                ),
            ]),
        ];
    }
}
