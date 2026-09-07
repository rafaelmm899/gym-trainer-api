<?php

namespace App\Data\Cycle;

use App\Services\Cycle\ProgressionSummaryService;
use Spatie\LaravelData\Data;

/**
 * One exercise's progression snapshot for the outgoing cycle, produced by
 * {@see ProgressionSummaryService} and fed into the N+1 planner prompt.
 * `actual*` fields and `trend` are computed straight from logged {@see
 * \App\Models\SetLog} rows — never from the exercise's standing {@see
 * \App\Models\ExerciseRecommendation} — so an athlete who outperforms their
 * target is reflected here, not masked by a stale suggestion.
 */
final class ExerciseProgressionData extends Data
{
    /**
     * @param  'up'|'down'|'flat'|'insufficient_data'  $trend
     */
    public function __construct(
        public readonly int $exerciseId,
        public readonly string $exerciseName,
        public readonly int $prescribedSets,
        public readonly int $prescribedRepMin,
        public readonly int $prescribedRepMax,
        public readonly ?float $prescribedWeightKg,
        public readonly bool $performed,
        public readonly ?float $actualAvgWeightKg,
        public readonly ?float $actualAvgReps,
        public readonly ?float $actualMaxRpe,
        public readonly string $trend,
        public readonly bool $plateauSignal,
    ) {}
}
