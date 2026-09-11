<?php

namespace App\Services\Cycle;

use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Exports\Cycle\CycleDayExport;
use App\Models\CycleDay;
use App\Models\Routine;
use Illuminate\Support\Str;

/**
 * Turns "export this day of this routine" into a ready {@see CycleDayExport}:
 * guard the routine's active cycle and that the day belongs to it, load the
 * day's prescriptions, and hand the export the day-exercises plus the routine's
 * active recommendations keyed by exercise. All row shaping is the export's job.
 */
final class CycleDayExportService
{
    public function handle(Routine $routine, CycleDay $day): CycleDayExport
    {
        $routine->loadMissing(['activeCycle', 'activeExerciseRecommendations']);

        $cycle = $routine->activeCycle;

        throw_if($cycle === null, new RoutineHasNoActiveCycleException);
        throw_unless($day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException);

        $day->loadMissing('dayExercises.exercise');

        return new CycleDayExport(
            $this->filename($routine, $cycle->sequence_number, $day),
            $day->dayExercises,
            $routine->activeExerciseRecommendations->keyBy('exercise_id'),
        );
    }

    private function filename(Routine $routine, int $sequenceNumber, CycleDay $day): string
    {
        return sprintf(
            '%s-ciclo-%d-dia-%d-%s.xlsx',
            $this->slug($routine->name, 'rutina'),
            $sequenceNumber,
            $day->order,
            $this->slug($day->label, 'sin-nombre'),
        );
    }

    private function slug(string $value, string $fallback): string
    {
        return Str::slug(Str::ascii($value)) ?: $fallback;
    }
}
