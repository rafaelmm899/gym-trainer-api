<?php

namespace App\Exports\Cycle;

use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * A flat, fillable `.xlsx` for one training day: a header row, then one row per
 * prescribed set — the prescription and the current recommendation on the left,
 * blank `weight_kg` / `reps` / `rpe` / `note` cells for the user to fill.
 *
 * A pure Laravel Excel adapter: it is handed the day's prescriptions and the
 * routine's active recommendations (keyed by exercise) and only shapes them into
 * rows. The guards and queries live in `App\Services\Cycle\CycleDayExportService`.
 */
final class CycleDayExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Collection<int, DayExercise>  $dayExercises  ordered by `order`, `exercise` loaded
     * @param  Collection<int, ExerciseRecommendation>  $recommendations  keyed by `exercise_id`, `status = active`
     */
    public function __construct(
        public readonly string $filename,
        private readonly Collection $dayExercises,
        private readonly Collection $recommendations,
    ) {}

    /**
     * @return Collection<int, DayExercise>
     */
    public function collection(): Collection
    {
        return $this->dayExercises;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
            'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
        ];
    }

    /**
     * One prescription → one row per prescribed set.
     *
     * @param  DayExercise  $row
     * @return list<list<string|int|float|null>>
     */
    public function map($row): array
    {
        if ($row->sets < 1) {
            return [];
        }

        $recommendation = $this->recommendations->get($row->exercise_id);

        $reps = $row->rep_min === $row->rep_max
            ? $row->rep_min
            : $row->rep_min.'-'.$row->rep_max;

        return array_map(fn (int $setNumber): array => [
            $row->exercise->name,
            $setNumber,
            $this->number($row->target_weight_kg),
            $reps,
            $this->number($row->target_rpe),
            $row->rest_seconds,
            $recommendation === null ? null : $this->number($recommendation->target_weight_kg),
            $recommendation?->action->value,
            null, null, null, null,
        ], range(1, $row->sets));
    }

    private function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
