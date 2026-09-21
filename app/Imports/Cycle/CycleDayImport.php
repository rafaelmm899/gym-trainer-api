<?php

namespace App\Imports\Cycle;

use App\Exports\Cycle\CycleDayExport;
use App\Services\Cycle\CycleDayImportService;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * The read-side mirror of {@see CycleDayExport}: declares
 * that the first row is a heading row (so `Excel::toCollection()` keys each
 * data row by its column name — `weight_kg`, `reps`, …). All row
 * interpretation, matching and validation live in
 * {@see CycleDayImportService}, not here.
 */
final class CycleDayImport implements Import, WithHeadingRow {}
