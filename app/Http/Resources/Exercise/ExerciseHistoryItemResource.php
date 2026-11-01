<?php

namespace App\Http\Resources\Exercise;

use App\Data\Exercise\ExercisePerformanceData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ExercisePerformanceData $resource
 */
class ExerciseHistoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->resource->sessionId,
            'started_at' => $this->resource->startedAt->toIso8601String(),
            'cycle_sequence_number' => $this->resource->cycleSequenceNumber,
            'cycle_day_label' => $this->resource->cycleDayLabel,
            'top_weight_kg' => $this->resource->topWeightKg,
            'volume_kg' => $this->resource->volumeKg,
            'volume_change_pct' => $this->resource->volumeChangePct,
            'sets' => $this->resource->sets,
        ];
    }
}
