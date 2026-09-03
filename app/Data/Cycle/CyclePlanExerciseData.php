<?php

namespace App\Data\Cycle;

use App\Services\Cycle\CyclePlannerService;
use Spatie\LaravelData\Data;

/**
 * One exercise prescription within a planned day, as returned by the AI planner
 * and validated by {@see CyclePlannerService}.
 */
final class CyclePlanExerciseData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $primaryMuscleGroup,
        public readonly int $sets,
        public readonly int $repMin,
        public readonly int $repMax,
        public readonly float $targetWeightKg,
        public readonly ?float $targetRpe,
        public readonly int $restSeconds,
        public readonly string $rationale,
    ) {}
}
