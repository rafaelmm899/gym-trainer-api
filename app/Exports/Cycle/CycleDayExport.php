<?php

namespace App\Exports\Cycle;

use App\Enums\Cycle\CycleStatus;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Exceptions\Cycle\RoutineHasNoActiveCycleException;
use App\Models\Cycle;
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
 * The workbook a user downloads for one day of their routine's active cycle. The
 * {@see WithHeadings} block is the `#` metadata prelude (day, split rationale,
 * one line per exercise with its rationale and current recommendation) followed
 * by the column header row; the {@see FromArray} rows are one per prescribed
 * set. `null` cells stay blank ({@see WithStrictNullComparison}) for the user to
 * fill in.
 *
 * The constructor runs the business guards (so an invalid day fails before any
 * bytes are written) and assembles every row up front. The `#` text is Spanish,
 * from `lang/es/export.php`; v1 is single-locale, so the locale is pinned here
 * rather than taken from `APP_LOCALE`.
 */
final class CycleDayExport implements FromArray, WithHeadings, WithStrictNullComparison
{
    /** @var list<string> */
    private const HEADER = [
        'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
        'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
    ];

    private const LOCALE = 'es';

    public readonly string $filename;

    /** @var list<list<string>> */
    private readonly array $headingRows;

    /** @var list<list<string|int|float|null>> */
    private readonly array $dataRows;

    public function __construct(Routine $routine, CycleDay $day, RecommendationCatalogService $recommendations)
    {
        $cycle = $routine->cycle()->first();

        throw_if($cycle === null || $cycle->status !== CycleStatus::Active, new RoutineHasNoActiveCycleException);
        throw_unless($day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException);

        $day->loadMissing('dayExercises.exercise');

        /** @var Collection<int, ExerciseRecommendation> $current */
        $current = $recommendations->listCurrentForRoutine($routine)->keyBy('exercise_id');

        $this->filename = $this->buildFilename($routine, $cycle, $day);
        $this->headingRows = [
            ...array_map(
                static fn (string $line): array => [$line],
                $this->commentLines($routine, $cycle, $day, $current),
            ),
            self::HEADER,
        ];
        $this->dataRows = $this->buildDataRows($day, $current);
    }

    /**
     * The `#` prelude lines then the column header row. Every element is an
     * array, so Laravel Excel writes each as its own row above the data.
     *
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return $this->headingRows;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return $this->dataRows;
    }

    /**
     * @param  Collection<int, ExerciseRecommendation>  $recommendations
     * @return list<list<string|int|float|null>>
     */
    private function buildDataRows(CycleDay $day, Collection $recommendations): array
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
                    $this->decimal($dayExercise->target_weight_kg),
                    $this->reps($dayExercise),
                    $this->decimal($dayExercise->target_rpe),
                    $dayExercise->rest_seconds,
                    $recommendation === null ? null : $this->decimal($recommendation->target_weight_kg),
                    $recommendation?->action->value,
                    null, null, null, null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ExerciseRecommendation>  $recommendations
     * @return list<string>
     */
    private function commentLines(Routine $routine, Cycle $cycle, CycleDay $day, Collection $recommendations): array
    {
        $lines = ['# '.$this->line('comment.day', [
            ':routine' => $routine->name,
            ':cycle' => (string) $cycle->sequence_number,
            ':day' => (string) $day->order,
            ':label' => $day->label,
            ':focus' => implode(', ', $day->focus_muscle_groups),
        ])];

        $splitRationale = $cycle->split_rationale;

        if ($splitRationale !== null && trim($splitRationale) !== '') {
            $lines[] = '# '.$this->line('comment.split_rationale', [
                ':rationale' => $this->collapse($splitRationale),
            ]);
        }

        foreach ($day->dayExercises as $dayExercise) {
            $lines[] = '# '.$this->exerciseLine($dayExercise, $recommendations->get($dayExercise->exercise_id));
        }

        return $lines;
    }

    private function exerciseLine(DayExercise $dayExercise, ?ExerciseRecommendation $recommendation): string
    {
        $line = $this->line('comment.exercise', [
            ':exercise' => $dayExercise->exercise->name,
            ':prescription' => $this->prescription($dayExercise),
            // The template supplies the sentence-ending period after :rationale.
            ':rationale' => rtrim($this->collapse($dayExercise->rationale), '.'),
        ]);

        if ($recommendation !== null) {
            $line .= ' '.$this->line('comment.exercise_recommendation', [
                ':action' => $recommendation->action->value,
                ':explanation' => $this->collapse($recommendation->explanation),
            ]);
        }

        return $line;
    }

    private function prescription(DayExercise $dayExercise): string
    {
        $fragment = $dayExercise->sets.'x'.$this->reps($dayExercise);

        if ($dayExercise->target_weight_kg !== null) {
            $fragment .= ' @ '.$this->numberText($dayExercise->target_weight_kg).'kg';
        }

        if ($dayExercise->target_rpe !== null) {
            $fragment .= ' RPE'.$this->numberText($dayExercise->target_rpe);
        }

        return $fragment.', '.$this->line('prescription.rest').' '.$dayExercise->rest_seconds.'s';
    }

    private function reps(DayExercise $dayExercise): int|string
    {
        return $dayExercise->rep_min === $dayExercise->rep_max
            ? $dayExercise->rep_min
            : $dayExercise->rep_min.'-'.$dayExercise->rep_max;
    }

    private function buildFilename(Routine $routine, Cycle $cycle, CycleDay $day): string
    {
        return implode('-', [
            $this->slug($routine->name, 'rutina'),
            $this->line('filename.cycle'),
            $cycle->sequence_number,
            $this->line('filename.day'),
            $day->order,
            $this->slug($day->label, 'sin-nombre'),
        ]).'.xlsx';
    }

    private function slug(string $value, string $fallback): string
    {
        $slug = Str::slug(Str::ascii($value));

        return $slug === '' ? $fallback : $slug;
    }

    private function decimal(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * A `decimal:*` cast string ("100.00") as the shortest exact decimal text
     * ("100", "102.5"), for the `#` prelude — independent of PHP's `precision`.
     */
    private function numberText(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * The `lang/es/export.php` template for `$key`, with `:token`s replaced via
     * `strtr` (simultaneous — a replacement value that contains a `:token` is
     * never re-expanded).
     *
     * @param  array<string, string>  $replacements
     */
    private function line(string $key, array $replacements = []): string
    {
        $template = trans('export.'.$key, [], self::LOCALE);
        $template = is_string($template) ? $template : $key;

        return $replacements === [] ? $template : strtr($template, $replacements);
    }
}
