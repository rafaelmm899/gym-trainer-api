<?php

namespace App\Data\Exercise;

use App\Enums\Shared\MuscleGroup;
use Spatie\LaravelData\Data;

/**
 * One exercise ready to be written to the catalogue, read from a dataset record
 * or from `database/catalog/manual_exercises.json`. A dataset record has an
 * `externalId`; a hand-written one has a `slug` instead and no external id.
 */
final class ExerciseImportData extends Data
{
    /**
     * @param  list<string>  $secondaryMuscles
     * @param  array<string, list<string>>  $instructions  steps per locale
     */
    public function __construct(
        public readonly ?string $externalId,
        public readonly ?string $slug,
        public readonly string $name,
        public readonly string $equipment,
        public readonly string $targetMuscle,
        public readonly array $secondaryMuscles,
        public readonly array $instructions,
        public readonly MuscleGroup $primaryMuscleGroup,
    ) {}
}
