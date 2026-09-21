<?php

namespace App\Imports\Cycle;

use App\Models\DayExercise;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Reads the day's filled workbook and validates every row through Laravel
 * Excel's own `WithValidation` pipeline — a row only has its fields checked
 * when both `weight_kg` and `reps` are present ({@see withValidator()}'s
 * `sometimes()` gate); a row missing either is a set the user hasn't trained
 * yet, not a validation failure, and is left alone. Field rules throw through
 * `Excel::import()` — the entrypoint that actually runs `WithValidation`,
 * unlike `Excel::toCollection()` — as one
 * `Maatwebsite\Excel\Validators\ValidationException` carrying every row's
 * failures at once.
 *
 * `App\Services\Cycle\CycleDayImportService` reads the resulting rows back out
 * via {@see rows()}: it still checks which are filled (the gate only skips
 * *validating* the others) and turns each into a `LogSetData`, since numbering
 * sets per exercise is business logic, not a file-format concern.
 */
final class CycleDayImport implements Import, ToCollection, WithHeadingRow, WithValidation
{
    /**
     * @var Collection<int, Collection<string, mixed>>
     */
    private Collection $rows;

    /**
     * @param  Collection<string, DayExercise>  $dayExercisesBySlug  keyed by the exercise's catalog slug
     */
    public function __construct(private readonly Collection $dayExercisesBySlug)
    {
        $this->rows = collect();
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * @return Collection<int, Collection<string, mixed>>
     */
    public function rows(): Collection
    {
        return $this->rows;
    }

    /**
     * Unconditional rules stay empty — every field is only ever required
     * through the `sometimes()` gate in {@see withValidator()}, so a row the
     * user never touched is never validated at all.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function customValidationMessages(): array
    {
        return [
            'weight_kg.numeric' => 'Must be a number greater than 0.',
            'weight_kg.gt' => 'Must be a number greater than 0.',
            'reps.numeric' => 'Must be a whole number greater than 0.',
            'reps.gt' => 'Must be a whole number greater than 0.',
            'reps.multiple_of' => 'Must be a whole number greater than 0.',
            'rpe.numeric' => 'Must be a number between 0 and 10.',
            'rpe.between' => 'Must be a number between 0 and 10.',
            'exercise.required' => 'Unknown exercise for this day.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $isFilled = static fn ($payload, $row): bool => filled($row->weight_kg ?? null) && filled($row->reps ?? null);

        $validator->sometimes('*.weight_kg', ['numeric', 'gt:0'], $isFilled);
        $validator->sometimes('*.reps', ['numeric', 'gt:0', 'multiple_of:1'], $isFilled);
        $validator->sometimes('*.rpe', ['nullable', 'numeric', 'between:0,10'], $isFilled);
        $validator->sometimes('*.exercise', ['required', $this->matchesDayExercise()], $isFilled);
    }

    private function matchesDayExercise(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->dayExercisesBySlug->has(Str::slug(Str::ascii((string) $value)))) {
                $fail('Unknown exercise for this day.');
            }
        };
    }
}
