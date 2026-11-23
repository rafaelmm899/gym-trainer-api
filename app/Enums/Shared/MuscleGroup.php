<?php

namespace App\Enums\Shared;

/**
 * The muscle groups the AI planner may target for a training day and tag on a
 * catalogued exercise (12 since the dataset catalogue: forearms and cardio). Shared across the Cycle and Exercise domains.
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
    case Forearms = 'forearms';
    case Cardio = 'cardio';

    /**
     * The group of a dataset `target` value, or null when the value is unknown.
     * The table is fixed: the dataset has 19 targets and a few of them have no
     * exact group, so they fall to the closest one.
     */
    public static function fromDatasetTarget(string $target): ?self
    {
        return match ($target) {
            'abs' => self::Core,
            'pectorals', 'serratus anterior' => self::Chest,
            'delts' => self::Shoulders,
            'upper back', 'lats', 'traps', 'spine', 'levator scapulae' => self::Back,
            'adductors', 'abductors', 'glutes' => self::Glutes,
            'forearms' => self::Forearms,
            'cardiovascular system' => self::Cardio,
            'biceps' => self::Biceps,
            'triceps' => self::Triceps,
            'quads' => self::Quads,
            'hamstrings' => self::Hamstrings,
            'calves' => self::Calves,
            default => null,
        };
    }

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
