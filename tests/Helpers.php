<?php

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;

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

    // 5 days x 5 exercises = 25 slots (5/day is valid for every experience-level
    // range). "Barbell Bench Press" repeats on days 1 and 5, so distinct
    // exercise names = 24 (the dedup assertions rely on this).
    return array_replace_recursive([
        'split_rationale' => 'A five-day split that hits each major group once with enough volume for hypertrophy.',
        'days' => [
            $day('Chest', ['chest', 'triceps'], [$exercise('Barbell Bench Press', 'chest'), $exercise('Incline Dumbbell Press', 'chest'), $exercise('Cable Fly', 'chest'), $exercise('Overhead Triceps Extension', 'triceps'), $exercise('Triceps Pushdown', 'triceps')]),
            $day('Back', ['back', 'biceps'], [$exercise('Deadlift', 'back'), $exercise('Barbell Row', 'back'), $exercise('Lat Pulldown', 'back'), $exercise('Seated Cable Row', 'back'), $exercise('Barbell Curl', 'biceps')]),
            $day('Legs', ['quads', 'hamstrings', 'glutes'], [$exercise('Back Squat', 'quads'), $exercise('Romanian Deadlift', 'hamstrings'), $exercise('Leg Press', 'quads'), $exercise('Leg Curl', 'hamstrings'), $exercise('Standing Calf Raise', 'calves')]),
            $day('Shoulders', ['shoulders'], [$exercise('Overhead Press', 'shoulders'), $exercise('Arnold Press', 'shoulders'), $exercise('Lateral Raise', 'shoulders'), $exercise('Rear Delt Fly', 'shoulders'), $exercise('Face Pull', 'back')]),
            $day('Arms', ['biceps', 'triceps', 'core'], [$exercise('Barbell Bench Press', 'chest'), $exercise('Close-Grip Bench Press', 'triceps'), $exercise('Preacher Curl', 'biceps'), $exercise('Hammer Curl', 'biceps'), $exercise('Cable Crunch', 'core')]),
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

/**
 * An active routine for the user with a real 5-day active cycle, each day
 * carrying three prescribed exercises — the fixture every training-session
 * test needs. Returns the routine with `cycle.cycleDays` eager-loaded.
 */
function trainingRoutineWithCycle(User $user): Routine
{
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create();

    CycleDay::factory()->count(5)->for($cycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create()
        ->each(fn (CycleDay $day) => DayExercise::factory()->count(3)->for($day)
            ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
            ->create());

    return $routine->load('cycle.cycleDays');
}

/**
 * An open (`in_progress`) session for the user, planned against the first day of
 * a real active cycle — the fixture for logging sets against a prescription.
 */
function openPlannedSession(User $user): TrainingSession
{
    $routine = trainingRoutineWithCycle($user);

    return TrainingSession::factory()->for($user)->for($routine)
        ->planned($routine->cycle->cycleDays->first())
        ->create()
        ->load('cycleDay.dayExercises.exercise');
}

/**
 * An open (`in_progress`) free session for the user — no cycle day, no
 * prescription. Sets are logged into it with a direct `exercise_id`. Reuses the
 * user's routine if they already have one (a user has a single active routine).
 */
function openFreeSession(User $user): TrainingSession
{
    $routine = $user->routines()->first() ?? Routine::factory()->for($user)->create();

    return TrainingSession::factory()->for($user)->for($routine)->create();
}
