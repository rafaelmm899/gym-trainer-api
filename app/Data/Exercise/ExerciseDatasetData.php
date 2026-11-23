<?php

namespace App\Data\Exercise;

use Spatie\LaravelData\Data;

/**
 * The outcome of reading dataset records: the rows that can be imported, the
 * records that were rejected and why, the names whose encoding was repaired and
 * the names that still hold unexpected non-ASCII characters.
 */
final class ExerciseDatasetData extends Data
{
    /**
     * @param  list<ExerciseImportData>  $rows
     * @param  list<array{id: string, reason: string}>  $rejected
     * @param  list<string>  $repaired
     * @param  list<string>  $suspicious
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $rejected,
        public readonly array $repaired,
        public readonly array $suspicious,
    ) {}
}
