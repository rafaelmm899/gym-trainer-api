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
use Maatwebsite\Excel\Validators\Failure;
use Maatwebsite\Excel\Validators\ValidationException as ExcelValidationException;
use Throwable;

/**
 * Turns "import this filled day" into ready-to-log data: guard the routine's
 * active cycle, that the day belongs to it, and that the day was not already
 * completed; read the uploaded workbook through `CycleDayImport`, which does
 * the actual field validation via Laravel Excel's own `WithValidation`
 * pipeline; then turn every filled row into a `LogSetData`, numbering each
 * matched exercise's sets contiguously from row order — the one piece of this
 * that is domain logic, not a file-format concern. No writes — {@see
 * TrainingSessionImportAction} does those, inside its own transaction, with
 * the `LogSetData` this returns.
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
        $dayExercisesBySlug = $this->dayExercisesBySlug($day->dayExercises);

        return $this->toLogSetData($this->readSheet($file, $dayExercisesBySlug), $dayExercisesBySlug);
    }

    /**
     * `Excel::import()` — not `Excel::toCollection()` — is the entrypoint that
     * actually runs the `WithValidation` pipeline: `toCollection()` only reads
     * the raw grid and never calls `$import->collection()` at all.
     *
     * @param  Collection<string, DayExercise>  $dayExercisesBySlug
     * @return Collection<int, Collection<string, mixed>>
     */
    private function readSheet(UploadedFile $file, Collection $dayExercisesBySlug): Collection
    {
        $import = new CycleDayImport($dayExercisesBySlug);

        try {
            Excel::import($import, $file);
        } catch (Throwable $e) {
            // Re-keyed from the library's own per-row Failure objects (which
            // already carry the real spreadsheet row number) into this API's
            // one `{field: [msg]}` VALIDATION_EXCEPTION envelope — the
            // library's own `errors()` returns a plain message list, not that
            // shape. Anything else (a corrupt or unparseable upload) folds
            // into the same envelope under `file`.
            throw ValidationException::withMessages(
                $e instanceof ExcelValidationException
                    ? $this->rowErrors($e->failures())
                    : ['file' => ['The uploaded file could not be read as a valid .xlsx spreadsheet.']],
            );
        }

        return $import->rows();
    }

    /**
     * @param  array<int, Failure>  $failures
     * @return array<string, list<string>>
     */
    private function rowErrors(array $failures): array
    {
        $errors = [];

        foreach ($failures as $failure) {
            $key = "row_{$failure->row()}.{$failure->attribute()}";
            $errors[$key] = array_merge($errors[$key] ?? [], $failure->errors());
        }

        return $errors;
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
     * Every row here already passed `CycleDayImport`'s validation (or was
     * never subject to it). "Filled" — both `weight_kg` and `reps` present —
     * is checked again here because the gate only skips *validating* an
     * unfilled row, not returning it.
     *
     * @param  Collection<int, Collection<string, mixed>>  $rows
     * @param  Collection<string, DayExercise>  $dayExercisesBySlug
     * @return Collection<int, LogSetData>
     */
    private function toLogSetData(Collection $rows, Collection $dayExercisesBySlug): Collection
    {
        $setNumbers = [];
        $logs = collect();

        foreach ($rows as $row) {
            $weight = $row['weight_kg'] ?? null;
            $reps = $row['reps'] ?? null;

            if (blank($weight) || blank($reps)) {
                continue;
            }

            /** @var DayExercise $dayExercise */
            $dayExercise = $dayExercisesBySlug->get(Str::slug(Str::ascii((string) $row['exercise'])));

            $setNumbers[$dayExercise->exercise_id] = ($setNumbers[$dayExercise->exercise_id] ?? 0) + 1;

            $rpe = $row['rpe'] ?? null;
            $note = $row['note'] ?? null;

            $logs->push(new LogSetData(
                set_number: $setNumbers[$dayExercise->exercise_id],
                weight_kg: (float) $weight,
                reps: (int) $reps,
                day_exercise_id: $dayExercise->uuid,
                rpe: blank($rpe) ? null : (float) $rpe,
                note: blank($note) ? null : (string) $note,
            ));
        }

        return $logs->values();
    }
}
