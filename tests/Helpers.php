<?php

use App\Ai\Agents\Cycle\CyclePlannerAgent;

/*
|--------------------------------------------------------------------------
| Test Helpers
|--------------------------------------------------------------------------
|
| Global helper functions shared across the test suite. Registered through
| `autoload-dev.files` in composer.json so they are available in every test
| without an import.
|
*/

/**
 * Regex matching a canonical lowercase UUID v4 string — the shape every API
 * `id` / route key takes (`HasPublicUuid`).
 */
function uuidV4Pattern(): string
{
    return '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
}

/**
 * Regex matching an ISO-8601 datetime with a numeric timezone offset — the
 * shape every timestamp takes in a JSON Resource (`toIso8601String()`).
 */
function iso8601Pattern(): string
{
    return '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
}

/**
 * A well-formed structured payload for the AI cycle planner: a 5-day split with
 * a full prescription per exercise. `array_replace_recursive` overrides let a
 * test bend one field (or swap `days` wholesale) to exercise a failure path.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cyclePlanPayload(array $overrides = []): array
{
    $exercise = fn (string $name, string $group): array => [
        'name' => $name,
        'primary_muscle_group' => $group,
        'sets' => 3,
        'rep_min' => 8,
        'rep_max' => 12,
        'target_weight_kg' => 40.0,
        'target_rpe' => 7.0,
        'rest_seconds' => 90,
        'rationale' => "Start moderate on {$name} and add load once the top rep range feels easy.",
    ];

    $day = fn (string $label, array $groups, array $exercises): array => [
        'label' => $label,
        'focus_muscle_groups' => $groups,
        'day_rationale' => "Focus on {$label} while the rest of the week recovers.",
        'exercises' => $exercises,
    ];

    return array_replace_recursive([
        'split_rationale' => 'A five-day split that hits each major group once with enough volume for hypertrophy.',
        'days' => [
            $day('Chest', ['chest', 'triceps'], [$exercise('Barbell Bench Press', 'chest'), $exercise('Incline Dumbbell Press', 'chest')]),
            $day('Back', ['back', 'biceps'], [$exercise('Barbell Row', 'back'), $exercise('Lat Pulldown', 'back')]),
            $day('Legs', ['quads', 'glutes'], [$exercise('Back Squat', 'quads'), $exercise('Romanian Deadlift', 'hamstrings')]),
            $day('Shoulders', ['shoulders'], [$exercise('Overhead Press', 'shoulders'), $exercise('Lateral Raise', 'shoulders')]),
            $day('Arms', ['biceps', 'triceps'], [$exercise('Barbell Curl', 'biceps'), $exercise('Triceps Pushdown', 'triceps')]),
        ],
    ], $overrides);
}

/**
 * Fake the cycle planner agent so every prompt returns the same canned
 * structured plan (a closure, not a one-element array, so a test that plans
 * more than once never falls through to schema-random fake data).
 *
 * @param  array<string, mixed>  $overrides  passed to {@see cyclePlanPayload()}
 */
function fakeCyclePlanner(array $overrides = []): void
{
    CyclePlannerAgent::fake(fn (): array => cyclePlanPayload($overrides));
}
