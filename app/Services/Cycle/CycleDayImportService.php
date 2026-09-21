<?php

namespace App\Services\Cycle;

use App\Actions\Session\TrainingSessionImportAction;
use App\Data\Session\LogSetData;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Exceptions\Session\CycleDayAlreadyCompletedException;
use App\Imports\Cycle\CycleDayImport;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\TrainingSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Turns "import this filled day" into ready-to-log data: guard the routine's
 * active cycle, that the day belongs to it, and that the day was not already
 * completed; read the uploaded workbook; match each filled row's `exercise`
 * cell to one of the day's prescriptions; validate `weight_kg` / `reps` /
 * `rpe`; and number each matched exercise's sets contiguously from row order.
 * No writes — {@see TrainingSessionImportAction} does
 * those, inside its own transaction, with the {@see LogSetData} this returns.
 */
final class CycleDayImportService
{
    /**
     * @return Collection<int, LogSetData>
     */
    public function handle(Routine $routine, CycleDay $day, UploadedFile $file): Collection
    {
        $routine->loadMissing('activeCycle');

        throw_if($routine->activeCycle === null, new RoutineHasNoActiveCycleException);
        throw_unless($day->cycle_id === $routine->activeCycle->id, new CycleDayNotInActiveCycleException);

        throw_if(
            TrainingSession::query()
                ->where('cycle_day_id', $day->id)
                ->where('status', SessionStatus::Completed)
                ->exists(),
            new CycleDayAlreadyCompletedException,
        );

        $day->loadMissing('dayExercises.exercise');

        return $this->parseRows($this->readSheet($file), $this->dayExercisesBySlug($day->dayExercises));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function readSheet(UploadedFile $file): Collection
    {
        // `UnreadableFileException` (and friends) surface for anything from a
        // truncated upload to a renamed non-spreadsheet file — folded into the
        // same row-error envelope as everything else the caller can fix.
        try {
            $rows = Excel::toCollection(new CycleDayImport, $file)->first();
        } catch (Throwable) {
            $rows = null;
        }

        if ($rows === null) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be read as a valid .xlsx spreadsheet.'],
            ]);
        }

        /** @var Collection<int, Collection<string, mixed>> $rows */
        return $rows->map(function (Collection $row): array {
            /** @var array<string, mixed> $array */
            $array = $row->toArray();

            return $array;
        });
    }

    /**
     * @param  Collection<int, DayExercise>  $dayExercises  ordered by `order`, `exercise` loaded
     * @return Collection<string, DayExercise> keyed by the exercise's catalog slug
     */
    private function dayExercisesBySlug(Collection $dayExercises): Collection
    {
        $bySlug = collect();

        foreach ($dayExercises as $dayExercise) {
            if (! $bySlug->has($dayExercise->exercise->slug)) {
                $bySlug->put($dayExercise->exercise->slug, $dayExercise);
            }
        }

        return $bySlug;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, DayExercise>  $dayExercisesBySlug
     * @return Collection<int, LogSetData>
     */
    private function parseRows(Collection $rows, Collection $dayExercisesBySlug): Collection
    {
        $errors = [];
        $setNumbers = [];
        $logs = collect();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // row 1 is the header; data starts at row 2

            $weight = $row['weight_kg'] ?? null;
            $reps = $row['reps'] ?? null;

            if ($this->blank($weight) || $this->blank($reps)) {
                continue;
            }

            $rowErrors = $this->validateRow($row, $weight, $reps, $dayExercisesBySlug);

            if ($rowErrors !== []) {
                foreach ($rowErrors as $field => $message) {
                    $errors["row_{$rowNumber}.{$field}"] = [$message];
                }

                continue;
            }

            /** @var DayExercise $dayExercise */
            $dayExercise = $dayExercisesBySlug->get($this->slug((string) $row['exercise']));

            $setNumbers[$dayExercise->exercise_id] = ($setNumbers[$dayExercise->exercise_id] ?? 0) + 1;

            $rpe = $row['rpe'] ?? null;
            $note = $row['note'] ?? null;

            $logs->push(new LogSetData(
                set_number: $setNumbers[$dayExercise->exercise_id],
                weight_kg: (float) $weight,
                reps: (int) $reps,
                day_exercise_id: $dayExercise->uuid,
                rpe: $this->blank($rpe) ? null : (float) $rpe,
                note: $this->blank($note) ? null : (string) $note,
            ));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $logs->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, DayExercise>  $dayExercisesBySlug
     * @return array<string, string>
     */
    private function validateRow(array $row, mixed $weight, mixed $reps, Collection $dayExercisesBySlug): array
    {
        $errors = [];

        if (! $dayExercisesBySlug->has($this->slug((string) ($row['exercise'] ?? '')))) {
            $errors['exercise'] = 'Unknown exercise for this day.';
        }

        if (! is_numeric($weight) || (float) $weight <= 0) {
            $errors['weight_kg'] = 'Must be a number greater than 0.';
        }

        if (! $this->isPositiveInteger($reps)) {
            $errors['reps'] = 'Must be a whole number greater than 0.';
        }

        $rpe = $row['rpe'] ?? null;

        if (! $this->blank($rpe) && (! is_numeric($rpe) || (float) $rpe < 0 || (float) $rpe > 10)) {
            $errors['rpe'] = 'Must be a number between 0 and 10.';
        }

        return $errors;
    }

    private function slug(string $value): string
    {
        return Str::slug(Str::ascii(trim($value)));
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        $float = (float) $value;

        return $float > 0 && $float === floor($float);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
