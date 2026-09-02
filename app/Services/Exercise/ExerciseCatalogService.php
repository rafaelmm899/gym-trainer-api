<?php

namespace App\Services\Exercise;

use App\Enums\Shared\MuscleGroup;
use App\Models\Exercise;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves a free-text exercise name (from the AI planner) to a row in the
 * global {@see Exercise} catalogue: normalise to a slug, reuse the row if it
 * exists, insert it otherwise. Semantic duplicates ("Bench Press" vs "Barbell
 * Bench Press") are only logged for a later manual merge — no alias table in v1.
 */
final class ExerciseCatalogService
{
    /**
     * Token-containment ratio (shared words / the shorter name's word count)
     * above which two differently-slugged names are logged as a probable
     * duplicate for a later manual merge.
     */
    private const DUPLICATE_CONTAINMENT_THRESHOLD = 0.8;

    public function resolve(string $name, ?string $muscleGroupHint): Exercise
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Exercise name cannot be blank.');
        }

        $slug = Str::slug(Str::ascii($name));

        $existing = Exercise::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $exercise = Exercise::query()->create([
            'name' => $name,
            'slug' => $slug,
            'primary_muscle_group' => MuscleGroup::tryFrom((string) $muscleGroupHint)?->value,
            'created_by_ai' => true,
        ]);

        $this->logProbableDuplicate($exercise);

        return $exercise;
    }

    private function logProbableDuplicate(Exercise $created): void
    {
        $tokens = $this->tokens($created->slug);

        $others = Exercise::query()
            ->where('id', '!=', $created->id)
            ->pluck('slug');

        foreach ($others as $otherSlug) {
            $otherTokens = $this->tokens($otherSlug);
            $shared = count(array_intersect($tokens, $otherTokens));
            $containment = $shared / max(1, min(count($tokens), count($otherTokens)));

            if ($containment >= self::DUPLICATE_CONTAINMENT_THRESHOLD) {
                Log::info("Possible duplicate exercise: '{$created->slug}' resembles '{$otherSlug}'.");

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tokens(string $slug): array
    {
        return array_values(array_filter(explode('-', $slug)));
    }
}
