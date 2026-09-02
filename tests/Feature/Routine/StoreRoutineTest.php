<?php

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Routine\RoutineStatus;
use App\Enums\Shared\Goal;
use App\Models\AthleteProfile;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function routinePayload(array $overrides = [], ?string $exceptKey = null): array
{
    $payload = array_merge([
        'name' => 'Winter Volume',
        'goal' => 'hypertrophy',
        'hint' => 'PPL split, dumbbells only',
    ], $overrides);

    if ($exceptKey !== null) {
        unset($payload[$exceptKey]);
    }

    return $payload;
}

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    fakeCyclePlanner();
    $this->user = User::factory()->create();
    AthleteProfile::factory()->for($this->user)->create();
});

// TC-1
it('creates the routine active with a nested draft cycle of 5 prescribed days', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload());

    $response->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.days_per_cycle', 5)
        ->assertJsonPath('data.cycle.status', 'draft')
        ->assertJsonPath('data.cycle.sequence_number', 1);

    expect($response->json('data.cycle.split_rationale'))->toBeString()->not->toBe('')
        ->and($response->json('data.cycle.generated_at'))->toMatch(iso8601Pattern())
        ->and($response->json('data.cycle.days'))->toHaveCount(5);

    $days = $response->json('data.cycle.days');
    expect(array_column($days, 'order'))->toBe([1, 2, 3, 4, 5])
        ->and($days[0]['label'])->toBe('Chest')
        ->and($days[0]['focus_muscle_groups'])->toBeArray()->not->toBeEmpty()
        ->and($days[0]['rationale'])->toBeString()->not->toBe('');

    $exercise = $days[0]['exercises'][0];
    expect($exercise)->toHaveKeys(['id', 'order', 'name', 'sets', 'rep_min', 'rep_max', 'target_weight_kg', 'target_rpe', 'rest_seconds', 'rationale'])
        ->and($exercise['name'])->toBe('Barbell Bench Press')
        ->and((float) $exercise['target_weight_kg'])->toBe(40.0)
        ->and($exercise['sets'])->toBe(3);

    $this->assertDatabaseCount('routines', 1);
    $this->assertDatabaseCount('cycles', 1);
    $this->assertDatabaseCount('cycle_days', 5);
    $this->assertDatabaseCount('day_exercises', 10);
    $this->assertDatabaseHas('cycles', [
        'sequence_number' => 1,
        'status' => 'draft',
    ]);
});

// TC-2
it('archives the previous active routine, permanently, and only the new one gets a cycle', function () {
    $previous = Routine::factory()->for($this->user)->create();

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    expect($previous->refresh()->status)->toBe(RoutineStatus::Archived)
        ->and($previous->archived_at)->not->toBeNull();

    $this->assertDatabaseCount('routines', 2);
    $this->assertDatabaseCount('cycles', 1);
    expect($this->user->routines()->where('status', RoutineStatus::Active)->count())->toBe(1);
});

// TC-3
it('returns 502 AI_GENERATION_FAILED and persists nothing when the planner throws', function () {
    CyclePlannerAgent::fake(fn () => throw new RuntimeException('provider unavailable'));

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(502)
        ->assertJsonPath('data.code', 'AI_GENERATION_FAILED')
        ->assertJsonMissingPath('data.errors');

    $this->assertDatabaseCount('routines', 0);
    $this->assertDatabaseCount('cycles', 0);
    $this->assertDatabaseCount('cycle_days', 0);
});

// TC-4
it('does not archive the incumbent active routine when generation fails', function () {
    $incumbent = Routine::factory()->for($this->user)->create();
    CyclePlannerAgent::fake(fn () => throw new RuntimeException('boom'));

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(502);

    expect($incumbent->refresh()->status)->toBe(RoutineStatus::Active)
        ->and($incumbent->archived_at)->toBeNull();

    $this->assertDatabaseCount('routines', 1);
    $this->assertDatabaseCount('cycles', 0);
});

// TC-5
it('returns 502 when the plan does not have exactly 5 days', function () {
    $payload = cyclePlanPayload();
    $payload['days'] = array_slice($payload['days'], 0, 4);
    CyclePlannerAgent::fake([$payload]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(502)
        ->assertJsonPath('data.code', 'AI_GENERATION_FAILED');

    $this->assertDatabaseCount('routines', 0);
    $this->assertDatabaseCount('cycles', 0);
});

// TC-6
it('returns 502 when a day has no exercises', function () {
    $payload = cyclePlanPayload();
    $payload['days'][4]['exercises'] = [];
    CyclePlannerAgent::fake([$payload]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(502);

    $this->assertDatabaseCount('routines', 0);
    $this->assertDatabaseCount('cycle_days', 0);
});

// TC-7
it('returns 502 when rep_min exceeds rep_max', function () {
    fakeCyclePlanner(['days' => [['exercises' => [['rep_min' => 12, 'rep_max' => 8]]]]]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(502);

    $this->assertDatabaseCount('routines', 0);
});

// TC-8
it('returns 502 when an exercise is missing its target weight', function () {
    fakeCyclePlanner(['days' => [['exercises' => [['target_weight_kg' => null]]]]]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(502);

    $this->assertDatabaseCount('routines', 0);
});

// TC-9
it('feeds the athlete profile and the routine goal and hint into the prompt', function () {
    $this->user->athleteProfile()->update([
        'experience_level' => ExperienceLevel::Intermediate->value,
        'days_per_week' => 5,
        'session_minutes' => 60,
        'goal' => Goal::Strength->value,
        'notes' => 'Left shoulder impingement, avoid heavy overhead pressing.',
    ]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload([
        'goal' => 'hypertrophy',
        'hint' => 'PPL split, dumbbells only',
    ]))->assertCreated();

    CyclePlannerAgent::assertPrompted(function ($prompt): bool {
        $text = $prompt->prompt;

        return str_contains($text, 'Left shoulder impingement')
            && str_contains($text, 'intermediate')
            && str_contains($text, '60')
            && str_contains($text, 'hypertrophy')
            && str_contains($text, 'PPL split, dumbbells only');
    });
});

// TC-10
it('rejects a user who has not completed onboarding without calling the planner', function () {
    $noProfile = User::factory()->create();

    $this->actingAs($noProfile)->postJson('/api/v1/routines', routinePayload())
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'PROFILE_INCOMPLETE');

    CyclePlannerAgent::assertNeverPrompted();
    $this->assertDatabaseCount('routines', 0);
    $this->assertDatabaseCount('cycles', 0);
});

// TC-11
it('validates the request shape before calling the planner', function (array $payload, string $field) {
    $this->actingAs($this->user)->postJson('/api/v1/routines', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field, 'data.errors');

    CyclePlannerAgent::assertNeverPrompted();
    $this->assertDatabaseCount('routines', 0);
})->with([
    'missing name' => [fn () => routinePayload(exceptKey: 'name'), 'name'],
    'missing goal' => [fn () => routinePayload(exceptKey: 'goal'), 'goal'],
    'bad goal' => [fn () => routinePayload(['goal' => 'powerlifting']), 'goal'],
]);

// TC-12
it('saves an omitted or blank hint as null and keeps it out of the prompt', function (array $payload) {
    $this->actingAs($this->user)->postJson('/api/v1/routines', $payload)
        ->assertCreated()
        ->assertJsonPath('data.hint', null);

    $this->assertDatabaseHas('routines', ['hint' => null]);

    CyclePlannerAgent::assertPrompted(
        fn ($prompt): bool => ! str_contains($prompt->prompt, 'Hint:') && ! str_contains($prompt->prompt, 'null')
    );
})->with([
    'omitted' => [fn () => routinePayload(exceptKey: 'hint')],
    'empty' => [fn () => routinePayload(['hint' => ''])],
    'whitespace' => [fn () => routinePayload(['hint' => '   '])],
]);

// TC-13
it('accepts every valid goal value and generates a draft cycle', function (string $goal) {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['goal' => $goal]))
        ->assertCreated()
        ->assertJsonPath('data.goal', $goal)
        ->assertJsonPath('data.cycle.status', 'draft');
})->with(['hypertrophy', 'strength', 'fat_loss', 'general_health', 'endurance']);

// TC-14
it('enforces the name and hint length boundaries', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['name' => str_repeat('a', 255)]))->assertCreated();
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['name' => str_repeat('a', 256)]))
        ->assertStatus(422)->assertJsonValidationErrors('name', 'data.errors');

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['hint' => str_repeat('a', 2000)]))->assertCreated();
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['hint' => str_repeat('a', 2001)]))
        ->assertStatus(422)->assertJsonValidationErrors('hint', 'data.errors');
});

// TC-15
it('ignores days_per_cycle sent in the request', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload(['days_per_cycle' => 3]))
        ->assertCreated()
        ->assertJsonPath('data.days_per_cycle', 5);

    $this->assertDatabaseHas('routines', ['user_id' => $this->user->id, 'days_per_cycle' => 5]);
});

// TC-16
it('exposes uuids everywhere in the response, never internal primary keys', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())->assertCreated();

    expect($response->json('data.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.cycle.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.cycle.days.0.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.cycle.days.0.exercises.0.id'))->toMatch(uuidV4Pattern());

    $response->assertJsonMissingPath('data.cycle.routine_id')
        ->assertJsonMissingPath('data.cycle.days.0.cycle_id')
        ->assertJsonMissingPath('data.cycle.days.0.exercises.0.exercise_id');
});

// TC-17
it('never touches another users routine or creates a cycle for them', function () {
    $other = User::factory()->create();
    AthleteProfile::factory()->for($other)->create();
    $otherRoutine = Routine::factory()->for($other)->create();

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())->assertCreated();

    expect($otherRoutine->refresh()->status)->toBe(RoutineStatus::Active)
        ->and($otherRoutine->archived_at)->toBeNull()
        ->and($other->routines()->first()->cycles()->count())->toBe(0);

    $this->assertDatabaseCount('cycles', 1);
});

// TC-18
it('renders the whole cycle tree without a lazy load under strict mode', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())
        ->assertCreated()
        ->assertJsonMissingPath('data.user')
        ->assertJsonCount(5, 'data.cycle.days')
        ->assertJsonCount(2, 'data.cycle.days.0.exercises');
});

// TC-19
it('inserts each exercise name once, slugged, created_by_ai', function () {
    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())->assertCreated();

    $this->assertDatabaseHas('exercises', [
        'slug' => 'barbell-bench-press',
        'name' => 'Barbell Bench Press',
        'created_by_ai' => true,
    ]);
    expect(Exercise::where('slug', 'barbell-bench-press')->count())->toBe(1)
        ->and(Exercise::count())->toBe(10);
});

// TC-20
it('reuses an existing catalogue row instead of duplicating it', function () {
    $existing = Exercise::factory()->create([
        'name' => 'Bench Press',
        'slug' => 'barbell-bench-press',
        'created_by_ai' => false,
    ]);

    $this->actingAs($this->user)->postJson('/api/v1/routines', routinePayload())->assertCreated();

    expect(Exercise::where('slug', 'barbell-bench-press')->count())->toBe(1)
        ->and($existing->refresh()->name)->toBe('Bench Press')
        ->and($existing->created_by_ai)->toBeFalse();

    $benchPrescription = DayExercise::query()
        ->whereHas('exercise', fn ($q) => $q->where('slug', 'barbell-bench-press'))
        ->first();
    expect($benchPrescription->exercise_id)->toBe($existing->id);
});
