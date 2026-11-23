<?php

namespace App\Actions\Exercise;

use App\Data\Exercise\ExerciseImportData;
use App\Data\Exercise\ExerciseImportReportData;
use App\Exceptions\Exercise\ExerciseDatasetUnreadableException;
use App\Models\Exercise;
use App\Services\Exercise\ExerciseDatasetService;
use Illuminate\Support\Facades\DB;

/**
 * Loads the exercise catalogue: every dataset record, then the hand-written
 * exercises, in one transaction. Idempotent.
 *
 * A dataset record is keyed by `external_id`; a hand-written one by `slug`. An
 * existing row only gets its catalogue fields refreshed: `name`, `slug`,
 * `created_by_ai` and a non-null `primary_muscle_group` are never touched, so
 * history and what users see do not move. A new row whose slug is already taken
 * gets `-<external_id>` appended, so same-named records never collide.
 */
final class ExerciseImportAction
{
    public function __construct(private ExerciseDatasetService $dataset) {}

    /**
     * @throws ExerciseDatasetUnreadableException
     */
    public function handle(?string $path = null): ExerciseImportReportData
    {
        $dataset = $this->dataset->parse($this->dataset->load($path));
        $manual = $this->dataset->manual();

        $rows = collect($dataset->rows)->sortBy('externalId')->values()->all();
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($rows, $manual, &$counts): void {
            foreach ($rows as $row) {
                $counts[$this->upsert($row, Exercise::query()->where('external_id', $row->externalId)->first())]++;
            }

            foreach ($manual->rows as $row) {
                $counts[$this->upsert($row, Exercise::query()->where('slug', $row->slug)->first())]++;
            }
        });

        return new ExerciseImportReportData(
            created: $counts['created'],
            updated: $counts['updated'],
            unchanged: $counts['unchanged'],
            rejected: [...$dataset->rejected, ...$manual->rejected],
            repaired: [...$dataset->repaired, ...$manual->repaired],
            suspicious: [...$dataset->suspicious, ...$manual->suspicious],
        );
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function upsert(ExerciseImportData $row, ?Exercise $existing): string
    {
        $fields = [
            'equipment' => $row->equipment,
            'target_muscle' => $row->targetMuscle,
            'secondary_muscles' => $row->secondaryMuscles,
            'instructions' => $row->instructions,
        ];

        if ($existing === null) {
            Exercise::query()->create([
                ...$fields,
                'name' => $row->name,
                'slug' => $row->slug ?? $this->freeSlug($row),
                'external_id' => $row->externalId,
                'primary_muscle_group' => $row->primaryMuscleGroup,
                'created_by_ai' => false,
            ]);

            return 'created';
        }

        $existing->fill($fields);
        $existing->primary_muscle_group ??= $row->primaryMuscleGroup;

        if (! $existing->isDirty()) {
            return 'unchanged';
        }

        $existing->save();

        return 'updated';
    }

    private function freeSlug(ExerciseImportData $row): string
    {
        $slug = Exercise::slugFor($row->name);

        return Exercise::query()->where('slug', $slug)->exists() ? "{$slug}-{$row->externalId}" : $slug;
    }
}
