<?php

namespace App\Services\Exercise;

use App\Data\Exercise\ExerciseDatasetData;
use App\Data\Exercise\ExerciseImportData;
use App\Enums\Shared\MuscleGroup;
use App\Exceptions\Exercise\ExerciseDatasetUnreadableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Reads the exercise dataset (a local file, or the pinned download) and the
 * hand-written exercises, validates every record and maps it to an
 * {@see ExerciseImportData}. A record that cannot be mapped is rejected with its
 * reason and never aborts the rest; only an unreadable source does.
 *
 * Names are title-cased to match the existing catalogue; known encoding damage
 * is repaired from `config('catalog.name_fixes')`, and any other non-ASCII name
 * is reported but left as it is.
 */
final class ExerciseDatasetService
{
    /**
     * @return list<mixed>
     *
     * @throws ExerciseDatasetUnreadableException
     */
    public function load(?string $path = null): array
    {
        $json = $path !== null ? $this->readFile($path) : $this->download();

        try {
            $records = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ExerciseDatasetUnreadableException('The dataset is not valid JSON: '.$e->getMessage());
        }

        throw_unless(is_array($records) && array_is_list($records), new ExerciseDatasetUnreadableException('The dataset must be a JSON list of records.'));

        return $records;
    }

    /**
     * The hand-written exercises: same shape as a dataset record, identified by
     * `slug` instead of `id`.
     *
     * @throws ExerciseDatasetUnreadableException
     */
    public function manual(): ExerciseDatasetData
    {
        return $this->parse($this->load(base_path('database/catalog/manual_exercises.json')));
    }

    /**
     * @param  list<mixed>  $records
     */
    public function parse(array $records): ExerciseDatasetData
    {
        $rows = [];
        $rejected = [];
        $repaired = [];
        $suspicious = [];

        foreach ($records as $record) {
            $label = is_array($record) ? (string) ($record['id'] ?? $record['slug'] ?? '?') : '?';
            $reason = $this->rejection($record);

            if ($reason !== null) {
                $rejected[] = ['id' => $label, 'reason' => $reason];

                continue;
            }

            /** @var array<string, mixed> $record */
            $raw = trim((string) $record['name']);
            $fixed = strtr($raw, (array) config('catalog.name_fixes'));
            $name = Str::title($fixed);

            if ($fixed !== $raw) {
                $repaired[] = $name;
            } elseif (preg_match('/[^\x00-\x7F°]/u', $fixed) === 1) {
                $suspicious[] = $name;
            }

            $rows[] = new ExerciseImportData(
                externalId: isset($record['id']) ? (string) $record['id'] : null,
                slug: isset($record['slug']) ? (string) $record['slug'] : null,
                name: $name,
                equipment: (string) $record['equipment'],
                targetMuscle: (string) $record['target'],
                secondaryMuscles: array_values($record['secondary_muscles']),
                instructions: $this->instructions($record['instruction_steps']),
                primaryMuscleGroup: MuscleGroup::fromDatasetTarget((string) $record['target']),
            );
        }

        return new ExerciseDatasetData($rows, $rejected, $repaired, $suspicious);
    }

    private function rejection(mixed $record): ?string
    {
        if (! is_array($record)) {
            return 'the record is not an object';
        }

        $hasId = isset($record['id']) && is_string($record['id']) && preg_match('/^\d{1,8}$/', $record['id']) === 1;
        $hasSlug = isset($record['slug']) && is_string($record['slug']) && $record['slug'] !== '';

        if (! $hasId && ! $hasSlug) {
            return 'it has neither a valid id nor a slug';
        }

        foreach (['name', 'equipment', 'target'] as $field) {
            if (! isset($record[$field]) || ! is_string($record[$field]) || trim($record[$field]) === '') {
                return "missing or empty '{$field}'";
            }
        }

        if (! isset($record['secondary_muscles']) || ! is_array($record['secondary_muscles']) || ! array_is_list($record['secondary_muscles'])) {
            return "'secondary_muscles' must be a list";
        }

        $steps = $record['instruction_steps'] ?? null;
        $primaryLocale = (string) config('catalog.locales.0');

        if (! is_array($steps) || $this->steps($steps[$primaryLocale] ?? null) === []) {
            return "missing '{$primaryLocale}' instruction steps";
        }

        if (MuscleGroup::fromDatasetTarget($record['target']) === null) {
            return "unknown target '{$record['target']}'";
        }

        return null;
    }

    /**
     * Only the configured locales, and only those that have at least one step.
     *
     * @param  array<string, mixed>  $steps
     * @return array<string, list<string>>
     */
    private function instructions(array $steps): array
    {
        $instructions = [];

        foreach ((array) config('catalog.locales') as $locale) {
            $localeSteps = $this->steps($steps[$locale] ?? null);

            if ($localeSteps !== []) {
                $instructions[(string) $locale] = $localeSteps;
            }
        }

        return $instructions;
    }

    /**
     * @return list<string>
     */
    private function steps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            fn (mixed $step): bool => is_string($step) && trim($step) !== '',
        ));
    }

    private function readFile(string $path): string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        throw_if($contents === false, new ExerciseDatasetUnreadableException("The dataset file '{$path}' cannot be read."));

        return $contents;
    }

    private function download(): string
    {
        $url = (string) config('catalog.dataset.url');

        try {
            return Http::timeout((int) config('catalog.dataset.timeout'))->get($url)->throw()->body();
        } catch (Throwable $e) {
            throw new ExerciseDatasetUnreadableException("The dataset cannot be downloaded from {$url}: ".$e->getMessage());
        }
    }
}
