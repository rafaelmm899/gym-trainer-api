<?php

namespace App\Http\Resources\Recommendation;

use App\Http\Resources\Exercise\ExerciseResource;
use App\Models\ExerciseRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExerciseRecommendation
 */
class ExerciseRecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'exercise' => ExerciseResource::make($this->whenLoaded('exercise')),
            'target_weight_kg' => (float) $this->target_weight_kg,
            'target_sets' => $this->target_sets,
            'target_rep_min' => $this->target_rep_min,
            'target_rep_max' => $this->target_rep_max,
            'action' => $this->action->value,
            'explanation' => $this->explanation,
        ];
    }
}
