<?php

namespace App\Data\Cycle;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * One planned training day: its label, the muscle groups it targets, the AI's
 * rationale for the day, and its ordered exercise prescriptions.
 */
final class CyclePlanDayData extends Data
{
    /**
     * @param  list<string>  $focusMuscleGroups
     * @param  list<CyclePlanExerciseData>  $exercises
     */
    public function __construct(
        public readonly string $label,
        public readonly array $focusMuscleGroups,
        public readonly string $rationale,
        #[DataCollectionOf(CyclePlanExerciseData::class)]
        public readonly array $exercises,
    ) {}
}
