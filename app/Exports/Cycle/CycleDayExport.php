<?php

namespace App\Exports\Cycle;

use App\Enums\Cycle\CycleStatus;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Services\Recommendation\RecommendationCatalogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * A flat, fillable workbook for one day of a routine's active cycle: a header
 * row, then one row per prescribed set — the prescription and the current
 * recommendation on the left, blank `weight_kg` / `reps` / `rpe` / `note` cells
 * on the right for the user to fill offline. The day's identity lives in the
 * filename; the day / exercise / split rationales stay in the JSON endpoints.
 *
 * The constructor runs the business guards, so an invalid day fails before any
 * bytes are written.
 */
final class CycleDayExport implements FromArray, WithHeadings, WithStrictNullComparison
{
    /** @var list<string> */
    private const HEADING = [
        'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
        'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
    ];

    public readonly string $filename;

    /** @var list<list<string|int|float|null>> */
    private readonly array $rows;

    public function __construct(Routine $routine, CycleDay $day, RecommendationCatalogService $recommendations)
    {
        $cycle = $routine->cycle()->first();

        throw_if($cycle === null || $cycle->status !== CycleStatus::Active, new RoutineHasNoActiveCycleException);
        throw_unless($day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException);

        $day->loadMissing('dayExercises.exercise');

        /** @var Collection<int, ExerciseRecommendation> $current */
        $current = $recommendations->listCurrentForRoutine($routine)->keyBy('exercise_id');

        $this->filename = sprintf(
            '%s-ciclo-%d-dia-%d-%s.xlsx',
            $this->slug($routine->name, 'rutina'),
            $cycle->sequence_number,
            $day->order,
            $this->slug($day->label, 'sin-nombre'),
        );

        $this->rows = $this->buildRows($day, $current);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return self::HEADING;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @param  Collection<int, ExerciseRecommendation>  $recommendations
     * @return list<list<string|int|float|null>>
     */
    private function buildRows(CycleDay $day, Collection $recommendations): array
    {
        $rows = [];
        $setNumberByExercise = [];

        foreach ($day->dayExercises as $dayExercise) {
            $recommendation = $recommendations->get($dayExercise->exercise_id);

            for ($set = 0; $set < $dayExercise->sets; $set++) {
                $setNumberByExercise[$dayExercise->exercise_id] = ($setNumberByExercise[$dayExercise->exercise_id] ?? 0) + 1;

                $rows[] = [
                    $dayExercise->exercise->name,
                    $setNumberByExercise[$dayExercise->exercise_id],
                    $this->number($dayExercise->target_weight_kg),
                    $this->reps($dayExercise),
                    $this->number($dayExercise->target_rpe),
                    $dayExercise->rest_seconds,
                    $recommendation === null ? null : $this->number($recommendation->target_weight_kg),
                    $recommendation?->action->value,
                    null, null, null, null,
                ];
            }
        }

        return $rows;
    }

    /**
     * `prescribed_reps` is a single integer when the range is a point, else the
     * `"min-max"` label.
     */
    private function reps(DayExercise $dayExercise): int|string
    {
        return $dayExercise->rep_min === $dayExercise->rep_max
            ? $dayExercise->rep_min
            : $dayExercise->rep_min.'-'.$dayExercise->rep_max;
    }

    private function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function slug(string $value, string $fallback): string
    {
        return Str::slug(Str::ascii($value)) ?: $fallback;
    }
}
