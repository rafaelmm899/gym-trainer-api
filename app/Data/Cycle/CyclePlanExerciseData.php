<?php

namespace App\Data\Cycle;

use App\Services\Cycle\CyclePlannerService;
use Spatie\LaravelData\Data;

/**
 * One exercise prescription within a planned day, validated by
 * {@see CyclePlannerService}. A first-cycle prescription comes from the AI and
 * is resolved to a catalogue row by `name`; a cycle N+1 prescription is cloned
 * from the outgoing cycle and carries the `exerciseId` it must keep. A cloned
 * `targetWeightKg` stays null when the outgoing prescription had none.
 */
final class CyclePlanExerciseData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $primaryMuscleGroup,
        public readonly int $sets,
        public readonly int $repMin,
        public readonly int $repMax,
        public readonly ?float $targetWeightKg,
        public readonly ?float $targetRpe,
        public readonly int $restSeconds,
        public readonly string $rationale,
        public readonly ?int $exerciseId = null,
    ) {}
}
