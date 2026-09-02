<?php

namespace App\Enums\Shared;

/**
 * The muscle groups the AI planner may target for a training day and tag on a
 * catalogued exercise. Shared across the Cycle and Exercise domains.
 */
enum MuscleGroup: string
{
    case Chest = 'chest';
    case Back = 'back';
    case Quads = 'quads';
    case Hamstrings = 'hamstrings';
    case Glutes = 'glutes';
    case Shoulders = 'shoulders';
    case Biceps = 'biceps';
    case Triceps = 'triceps';
    case Calves = 'calves';
    case Core = 'core';

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
