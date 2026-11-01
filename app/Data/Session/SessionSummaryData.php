<?php

namespace App\Data\Session;

use Spatie\LaravelData\Data;

final class SessionSummaryData extends Data
{
    public function __construct(
        public readonly int $exercisesCount,
        public readonly int $setsCount,
        public readonly float $totalVolumeKg,
        public readonly ?int $durationSeconds,
        public readonly ?float $volumeChangePct,
    ) {}
}
