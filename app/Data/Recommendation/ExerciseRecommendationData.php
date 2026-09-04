<?php

namespace App\Data\Recommendation;

use App\Enums\Recommendation\RecommendationAction;
use App\Services\Recommendation\SessionAnalystService;
use Spatie\LaravelData\Data;

/**
 * One exercise's next-time target, as decided by
 * {@see SessionAnalystService} from the AI
 * analyst's structured response. `exerciseId` is resolved locally from the
 * session's own sets — never trusted from the AI response.
 */
final class ExerciseRecommendationData extends Data
{
    public function __construct(
        public readonly int $exerciseId,
        public readonly float $targetWeightKg,
        public readonly int $targetSets,
        public readonly int $targetRepMin,
        public readonly int $targetRepMax,
        public readonly RecommendationAction $action,
        public readonly string $explanation,
    ) {}
}
