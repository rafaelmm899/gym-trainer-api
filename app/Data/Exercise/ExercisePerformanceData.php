<?php

namespace App\Data\Exercise;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * What one completed session did on one exercise: its sets plus the derived
 * top set and volume. `volumeChangePct` is only filled for history items.
 */
final class ExercisePerformanceData extends Data
{
    /**
     * @param  list<array{set_number: int, weight_kg: float, reps: int, rpe: float|null, note: string|null}>  $sets
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly CarbonImmutable $startedAt,
        public readonly ?int $cycleSequenceNumber,
        public readonly ?string $cycleDayLabel,
        public readonly float $topWeightKg,
        public readonly int $topSetReps,
        public readonly float $volumeKg,
        public readonly array $sets,
        public readonly ?float $volumeChangePct = null,
    ) {}

    public function withVolumeChangePct(?float $volumeChangePct): self
    {
        return new self(
            $this->sessionId,
            $this->startedAt,
            $this->cycleSequenceNumber,
            $this->cycleDayLabel,
            $this->topWeightKg,
            $this->topSetReps,
            $this->volumeKg,
            $this->sets,
            $volumeChangePct,
        );
    }
}
