<?php

namespace App\Data\Exercise;

use Spatie\LaravelData\Data;

/**
 * What an `exercises:import` run did, for the command to print.
 */
final class ExerciseImportReportData extends Data
{
    /**
     * @param  list<array{id: string, reason: string}>  $rejected
     * @param  list<string>  $repaired  names whose encoding was repaired
     * @param  list<string>  $suspicious  names with unexpected non-ASCII characters
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly array $rejected,
        public readonly array $repaired,
        public readonly array $suspicious,
    ) {}
}
