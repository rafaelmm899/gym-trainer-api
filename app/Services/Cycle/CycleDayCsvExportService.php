<?php

namespace App\Services\Cycle;

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
use RuntimeException;

/**
 * Builds the CSV a user downloads for one day of their routine's active cycle:
 * a `#` comment prelude (day metadata, split rationale, one line per exercise
 * with its rationale and current recommendation) followed by the fixed header
 * row and one row per prescribed set. The `#` text is Spanish, from
 * `lang/es/export.php`; v1 is single-locale, so the locale is pinned here rather
 * than taken from `APP_LOCALE`.
 */
final class CycleDayCsvExportService
{
    /** @var list<string> */
    private const HEADER = [
        'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
        'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
    ];

    private const LOCALE = 'es';

    public function __construct(private RecommendationCatalogService $recommendations) {}

    /**
     * @return array{filename: string, contents: string}
     */
    public function handle(Routine $routine, CycleDay $day): array
    {
        $cycle = $routine->cycle()->first();

        throw_if($cycle === null || $cycle->status !== CycleStatus::Active, new RoutineHasNoActiveCycleException);
        throw_unless($day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException);

        $day->loadMissing('dayExercises.exercise');

        /** @var Collection<int, ExerciseRecommendation> $recommendations */
        $recommendations = $this->recommendations->listCurrentForRoutine($routine)->keyBy('exercise_id');

        return [
            'filename' => $this->filename($routine, $cycle, $day),
            'contents' => $this->contents($routine, $cycle, $day, $recommendations),
        ];
    }

    /**
     * @param  Collection<int, ExerciseRecommendation>  $recommendations
     */
    private function contents(Routine $routine, Cycle $cycle, CycleDay $day, Collection $recommendations): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open an in-memory stream for CSV assembly.');
        }

        foreach ($this->commentLines($routine, $cycle, $day, $recommendations) as $line) {
            fwrite($handle, $line."\n");
        }

        fputcsv($handle, self::HEADER, escape: '');

        $setNumberByExercise = [];

        foreach ($day->dayExercises as $dayExercise) {
            $recommendation = $recommendations->get($dayExercise->exercise_id);

            for ($set = 0; $set < $dayExercise->sets; $set++) {
                $setNumberByExercise[$dayExercise->exercise_id] = ($setNumberByExercise[$dayExercise->exercise_id] ?? 0) + 1;

                fputcsv($handle, [
                    $dayExercise->exercise->name,
                    $setNumberByExercise[$dayExercise->exercise_id],
                    $dayExercise->target_weight_kg === null ? '' : $this->num($dayExercise->target_weight_kg),
                    $this->reps($dayExercise),
                    $dayExercise->target_rpe === null ? '' : $this->num($dayExercise->target_rpe),
                    $dayExercise->rest_seconds,
                    $recommendation === null ? '' : $this->num($recommendation->target_weight_kg),
                    $recommendation?->action->value ?? '',
                    '', '', '', '',
                ], escape: '');
            }
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
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
            ':rationale' => $this->collapse($dayExercise->rationale),
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
            $fragment .= ' @ '.$this->num($dayExercise->target_weight_kg).'kg';
        }

        if ($dayExercise->target_rpe !== null) {
            $fragment .= ' RPE'.$this->num($dayExercise->target_rpe);
        }

        return $fragment.', '.$this->line('prescription.rest').' '.$dayExercise->rest_seconds.'s';
    }

    private function reps(DayExercise $dayExercise): string
    {
        return $dayExercise->rep_min === $dayExercise->rep_max
            ? (string) $dayExercise->rep_min
            : $dayExercise->rep_min.'-'.$dayExercise->rep_max;
    }

    private function filename(Routine $routine, Cycle $cycle, CycleDay $day): string
    {
        return implode('-', [
            $this->slug($routine->name, 'rutina'),
            $this->line('filename.cycle'),
            $cycle->sequence_number,
            $this->line('filename.day'),
            $day->order,
            $this->slug($day->label, 'sin-nombre'),
        ]).'.csv';
    }

    private function slug(string $value, string $fallback): string
    {
        $slug = Str::slug(Str::ascii($value));

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * Decimals arrive as `decimal:*` cast strings ("100.00"). Trim to the
     * shortest exact form ("100", "102.5"), independent of PHP's `precision`.
     */
    private function num(string $value): string
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
