<?php

namespace App\Enums\Recommendation;

use App\Services\Recommendation\SessionAnalystService;

/**
 * What the athlete should do differently next time this exercise comes up,
 * decided by {@see SessionAnalystService} from the
 * sets just logged against the exercise's prior target (or, absent one, its
 * cycle-day prescription).
 */
enum RecommendationAction: string
{
    case AdvanceWeight = 'advance_weight';
    case Hold = 'hold';
    case AddReps = 'add_reps';
    case AddSet = 'add_set';
    case Deload = 'deload';
    case TechniqueFocus = 'technique_focus';

    /**
     * The backed values, for a JSON-schema `enum` constraint or a validation
     * `in:` rule.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
