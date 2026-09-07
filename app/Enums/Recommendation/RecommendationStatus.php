<?php

namespace App\Enums\Recommendation;

use App\Models\ExerciseRecommendation;

/**
 * Whether an {@see ExerciseRecommendation} is still a pending
 * target ({@see self::Active}) or was already folded into a generated cycle
 * ({@see self::Applied}). A fresh session analysis always resets a row back to
 * {@see self::Active}, even one a prior rollover had marked {@see self::Applied}.
 */
enum RecommendationStatus: string
{
    case Active = 'active';
    case Applied = 'applied';
}
