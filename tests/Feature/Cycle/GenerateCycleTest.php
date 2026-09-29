<?php

use App\Ai\Agents\Cycle\CycleProgressionAgent;
use App\Enums\Recommendation\RecommendationStatus;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;

// TC-1..TC-17 — generate-next-cycle-spec.md §8; keep-cycle-exercises-spec.md §8

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    fakeCycleProgression();
    $this->user = User::factory()->create();
    AthleteProfile::factory()->for($this->user)->create();
});

/**
 * Marks a cycle day as trained: one completed session against it, with one
 * logged set for its first prescribed exercise.
 */
function trainDay(Routine $routine, User $user, CycleDay $day): TrainingSession
{
    $day->loadMissing('dayExercises.exercise');
    $session = TrainingSession::factory()->for($user)->for($routine)->completed()->planned($day)->create();
    SetLog::factory()->for($session, 'session')->for($day->dayExercises->first()->exercise, 'exercise')->create();

    return $session;
}

/**
 * The user's routine with its active cycle (5 days x 3 exercises), loaded
 * with everything `trainDay()` needs.
 */
function loadedRoutine(User $user): Routine
{
    return trainingRoutineWithCycle($user)->load('cycle.cycleDays.dayExercises.exercise');
}

/**
 * @return array<int, array<int, array<string, mixed>>> exercise identity per day (by day order) and position, from the database
 */
function exerciseLayout(Cycle $cycle): array
{
    return $cycle->load('cycleDays.dayExercises')->cycleDays->sortBy('order')
        ->map(fn (CycleDay $day): array => $day->dayExercises->sortBy('order')->pluck('exercise_id')->values()->all())
        ->values()
        ->all();
}

// keep-cycle-exercises-spec.md TC-1
it('keeps the exact same exercises, days and order in the next cycle', function () {
    $routine = loadedRoutine($this->user);
    $outgoingCycle = $routine->cycle;
    $outgoingCycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));
    $outgoingLayout = exerciseLayout($outgoingCycle);

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    $newCycle = Cycle::query()->where('routine_id', $routine->id)->where('sequence_number', 2)->sole();
    expect(exerciseLayout($newCycle))->toBe($outgoingLayout);

    foreach ($outgoingCycle->cycleDays->sortBy('order')->values() as $index => $day) {
        $response->assertJsonPath("data.days.{$index}.label", $day->label)
            ->assertJsonPath("data.days.{$index}.focus_muscle_groups", $day->focus_muscle_groups)
            ->assertJsonPath("data.days.{$index}.rationale", $day->rationale)
            ->assertJsonCount($day->dayExercises->count(), "data.days.{$index}.exercises");
    }
});

// keep-cycle-exercises-spec.md TC-2
it('applies the AI progression to the cloned exercises', function () {
    fakeCycleProgression(
        ['1.1' => ['sets' => 4, 'rep_min' => 6, 'rep_max' => 8, 'target_weight_kg' => 62.5, 'target_rpe' => 8.0, 'rest_seconds' => 150, 'rationale' => 'Top of range on every set — add load.']],
        ['split_rationale' => 'Load goes up on the lifts that hit the top of the range.'],
    );
    $routine = loadedRoutine($this->user);
    $outgoingExercise = $routine->cycle->cycleDays->firstWhere('order', 1)->dayExercises->firstWhere('order', 1)->exercise;
    $routine->cycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(201)
        ->assertJsonPath('data.split_rationale', 'Load goes up on the lifts that hit the top of the range.')
        ->assertJsonPath('data.days.0.exercises.0.name', $outgoingExercise->name)
        ->assertJsonPath('data.days.0.exercises.0.sets', 4)
        ->assertJsonPath('data.days.0.exercises.0.rep_min', 6)
        ->assertJsonPath('data.days.0.exercises.0.rep_max', 8)
        ->assertJsonPath('data.days.0.exercises.0.target_weight_kg', 62.5)
        ->assertJsonPath('data.days.0.exercises.0.target_rpe', 8)
        ->assertJsonPath('data.days.0.exercises.0.rest_seconds', 150)
        ->assertJsonPath('data.days.0.exercises.0.rationale', 'Top of range on every set — add load.');
});

// keep-cycle-exercises-spec.md TC-3
it('copies an exercise that was not performed verbatim and never sends it to the AI', function () {
    $routine = loadedRoutine($this->user);
    $days = $routine->cycle->cycleDays->sortBy('order')->values();
    $untrainedSlot = $days[1]->dayExercises->firstWhere('order', 1);
    $days->reject(fn (CycleDay $day): bool => $day->order === 2)->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    $response->assertJsonPath('data.days.1.exercises.0.name', $untrainedSlot->exercise->name)
        ->assertJsonPath('data.days.1.exercises.0.sets', $untrainedSlot->sets)
        ->assertJsonPath('data.days.1.exercises.0.rep_min', $untrainedSlot->rep_min)
        ->assertJsonPath('data.days.1.exercises.0.rep_max', $untrainedSlot->rep_max)
        ->assertJsonPath('data.days.1.exercises.0.rest_seconds', $untrainedSlot->rest_seconds)
        ->assertJsonPath('data.days.1.exercises.0.rationale', $untrainedSlot->rationale);

    // A whole-number decimal (e.g. 8.0) decodes from JSON as an int, so compare loosely.
    expect($response->json('data.days.1.exercises.0.target_weight_kg'))->toEqual((float) $untrainedSlot->target_weight_kg)
        ->and($response->json('data.days.1.exercises.0.target_rpe'))->toEqual((float) $untrainedSlot->target_rpe);

    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, $untrainedSlot->exercise->name));
});

// keep-cycle-exercises-spec.md TC-4 (and generate-next-cycle-spec.md TC-4)
it('clones the whole cycle without calling the AI when the outgoing week was never trained', function () {
    $routine = loadedRoutine($this->user);
    $outgoingCycle = $routine->cycle;
    $outgoingLayout = exerciseLayout($outgoingCycle);

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    CycleProgressionAgent::assertNeverPrompted();
    expect($response->json('data.split_rationale'))->not->toBe('')
        ->and(exerciseLayout(Cycle::query()->where('routine_id', $routine->id)->where('sequence_number', 2)->sole()))->toBe($outgoingLayout)
        ->and($outgoingCycle->refresh()->status->value)->toBe('incomplete');

    $this->assertDatabaseMissing('exercise_recommendations', ['status' => 'applied']);
});

// keep-cycle-exercises-spec.md TC-5, TC-6
it('prompts the progression agent with the profile, routine goal/hint, performed slots and active recommendations', function () {
    $routine = loadedRoutine($this->user);
    $routine->update(['goal' => 'strength', 'hint' => 'Focus on compound lifts.']);
    $this->user->athleteProfile->update(['notes' => 'Distinctive profile notes.']);

    $day = $routine->cycle->cycleDays->firstWhere('order', 1);
    $slot = $day->dayExercises->firstWhere('order', 1);
    trainDay($routine, $this->user, $day);

    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($slot->exercise)->create([
        'action' => 'advance_weight',
        'explanation' => 'Distinctive explanation marker.',
    ]);

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Distinctive profile notes.')
        && str_contains($prompt->prompt, 'strength')
        && str_contains($prompt->prompt, 'Focus on compound lifts.')
        && str_contains($prompt->prompt, "- day 1, exercise 1 — {$slot->exercise->name}: prescribed {$slot->sets}x{$slot->rep_min}-{$slot->rep_max}")
        && str_contains($prompt->prompt, 'recommendation: advance_weight')
        && str_contains($prompt->prompt, 'Distinctive explanation marker.')
        && str_contains($prompt->prompt, 'Return exactly one progression per listed slot'));

    expect((new CycleProgressionAgent)->instructions())->toContain('Never add, remove, replace or reorder exercises');
});

// keep-cycle-exercises-spec.md TC-7, TC-8
it('returns 502 and persists nothing when the response misses, adds or repeats a slot', function (Closure $mutate) {
    CycleProgressionAgent::fake(fn (string $prompt): array => $mutate(cycleProgressionPayload($prompt)));
    $routine = loadedRoutine($this->user);
    $outgoingCycle = $routine->cycle;
    $outgoingCycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));
    $recommendation = ExerciseRecommendation::factory()->for($this->user)->for($routine)
        ->for($outgoingCycle->cycleDays->first()->dayExercises->first()->exercise)->create();

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(502)
        ->assertJsonPath('data.code', 'AI_GENERATION_FAILED');

    $this->assertDatabaseCount('cycles', 1);
    expect($outgoingCycle->refresh()->status->value)->toBe('active')
        ->and($recommendation->refresh()->status)->toBe(RecommendationStatus::Active);
})->with([
    'missing slot' => [function (array $payload): array {
        array_pop($payload['progressions']);

        return $payload;
    }],
    'extra slot' => [function (array $payload): array {
        $payload['progressions'][] = [...$payload['progressions'][0], 'exercise' => 9];

        return $payload;
    }],
    'repeated slot' => [function (array $payload): array {
        $payload['progressions'][] = $payload['progressions'][0];

        return $payload;
    }],
    'unknown day' => [function (array $payload): array {
        $payload['progressions'][] = [...$payload['progressions'][0], 'day' => 6];

        return $payload;
    }],
]);

// keep-cycle-exercises-spec.md TC-9
it('returns 502 and persists nothing for an out-of-bounds progression value', function (array $overrides) {
    fakeCycleProgression(['1.1' => $overrides]);
    $routine = loadedRoutine($this->user);
    $routine->cycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(502)
        ->assertJsonPath('data.code', 'AI_GENERATION_FAILED');

    $this->assertDatabaseCount('cycles', 1);
})->with([
    'zero sets' => [['sets' => 0]],
    'reps inverted' => [['rep_min' => 10, 'rep_max' => 8]],
    'negative weight' => [['target_weight_kg' => -5.0]],
    'rpe above 10' => [['target_rpe' => 11.0]],
    'negative rest' => [['rest_seconds' => -1]],
    'blank rationale' => [['rationale' => '']],
]);

// keep-cycle-exercises-spec.md TC-10 (and generate-next-cycle-spec.md TC-9)
it('returns 502 and persists nothing when the provider throws', function () {
    $routine = loadedRoutine($this->user);
    $outgoingCycle = $routine->cycle;
    $outgoingCycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));
    CycleProgressionAgent::fake(fn () => throw new RuntimeException('provider unavailable'));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(502)
        ->assertJsonPath('data.code', 'AI_GENERATION_FAILED');

    $this->assertDatabaseCount('cycles', 1);
    expect($outgoingCycle->refresh()->status->value)->toBe('active');
});

// keep-cycle-exercises-spec.md TC-11
it('keeps an exercise that sits on two days and gives each slot its own returned values', function () {
    fakeCycleProgression(['1.1' => ['target_weight_kg' => 50.0], '5.1' => ['target_weight_kg' => 55.0]]);
    $routine = loadedRoutine($this->user);
    $days = $routine->cycle->cycleDays->sortBy('order')->values();
    $shared = $days[0]->dayExercises->firstWhere('order', 1)->exercise;
    $days[4]->dayExercises->firstWhere('order', 1)->update(['exercise_id' => $shared->id]);
    trainDay($routine, $this->user, $days[0]);

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(201)
        ->assertJsonPath('data.days.0.exercises.0.name', $shared->name)
        ->assertJsonPath('data.days.0.exercises.0.target_weight_kg', 50)
        ->assertJsonPath('data.days.4.exercises.0.name', $shared->name)
        ->assertJsonPath('data.days.4.exercises.0.target_weight_kg', 55);

    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '- day 1, exercise 1 —')
        && str_contains($prompt->prompt, '- day 5, exercise 1 —'));
});

// keep-cycle-exercises-spec.md TC-12
it('clones the outgoing cycle as the user last edited it', function () {
    $routine = loadedRoutine($this->user);
    $replacement = Exercise::factory()->create();
    $routine->cycle->cycleDays->firstWhere('order', 1)->dayExercises->firstWhere('order', 1)->update(['exercise_id' => $replacement->id]);
    $routine->load('cycle.cycleDays.dayExercises.exercise');
    $routine->cycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(201)
        ->assertJsonPath('data.days.0.exercises.0.name', $replacement->name);
});

// keep-cycle-exercises-spec.md TC-25
it('clones a day whose exercise count differs from its neighbours as it is', function () {
    $routine = loadedRoutine($this->user);
    $routine->cycle->cycleDays->firstWhere('order', 3)->dayExercises->firstWhere('order', 3)->delete();
    $routine->load('cycle.cycleDays.dayExercises.exercise');
    $routine->cycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(201)
        ->assertJsonCount(3, 'data.days.0.exercises')
        ->assertJsonCount(2, 'data.days.2.exercises');
});

// keep-cycle-exercises-spec.md TC-13a
it('rolls the outgoing cycle to completed and marks trained recommendations applied', function () {
    $routine = loadedRoutine($this->user);
    $outgoingCycle = $routine->cycle;
    $days = $outgoingCycle->cycleDays->sortBy('order')->values();
    $days->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $trainedOne = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($days[0]->dayExercises->first()->exercise)->create();
    $trainedTwo = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($days[1]->dayExercises->first()->exercise)->create();
    $untrained = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($days[2]->dayExercises->last()->exercise)->create();

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($outgoingCycle->refresh()->status->value)->toBe('completed')
        ->and($outgoingCycle->completed_at)->not->toBeNull()
        ->and($trainedOne->refresh()->status)->toBe(RecommendationStatus::Applied)
        ->and($trainedTwo->refresh()->status)->toBe(RecommendationStatus::Applied)
        ->and($untrained->refresh()->status)->toBe(RecommendationStatus::Active);
});

// keep-cycle-exercises-spec.md TC-13b
it('rolls the outgoing cycle to incomplete when fewer than 5 days were trained', function () {
    $routine = loadedRoutine($this->user);
    $outgoingCycle = $routine->cycle;
    $outgoingCycle->cycleDays->take(3)->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($outgoingCycle->refresh()->status->value)->toBe('incomplete')
        ->and($outgoingCycle->completed_at)->not->toBeNull();
});

// keep-cycle-exercises-spec.md TC-13c
it('leaves an already-applied recommendation for a trained exercise applied', function () {
    $routine = loadedRoutine($this->user);
    $day = $routine->cycle->cycleDays->first();
    trainDay($routine, $this->user, $day);
    $recommendation = ExerciseRecommendation::factory()->applied()->for($this->user)->for($routine)->for($day->dayExercises->first()->exercise)->create();

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($recommendation->refresh()->status)->toBe(RecommendationStatus::Applied);
});

// keep-cycle-exercises-spec.md TC-13c — guards
it('rejects an ineligible request before it reaches the AI', function (string $case) {
    $other = User::factory()->create();
    $routine = match ($case) {
        'archived' => tap(Routine::factory()->archived()->for($this->user)->create(), fn (Routine $r) => Cycle::factory()->completed()->for($r)->create()),
        'foreign' => trainingRoutineWithCycle($other),
    };

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles");

    match ($case) {
        'archived' => $response->assertStatus(409)->assertJsonPath('data.code', 'ROUTINE_NOT_ACTIVE'),
        'foreign' => $response->assertStatus(403),
    };

    $this->assertDatabaseCount('cycles', 1);
    CycleProgressionAgent::assertNeverPrompted();
})->with(['archived', 'foreign']);

it('returns 404 for an unknown routine uuid', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines/00000000-0000-4000-8000-000000000000/cycles')
        ->assertStatus(404);
});

it('returns 401 when unauthenticated', function () {
    $this->postJson('/api/v1/routines/00000000-0000-4000-8000-000000000000/cycles')
        ->assertStatus(401);
});

// keep-cycle-exercises-spec.md TC-13d
it('rate-limits a second call within a minute and exposes uuids, never internal PKs', function () {
    $routine = loadedRoutine($this->user);

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($response->json('data.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.days.0.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.days.0.exercises.0.id'))->toMatch(uuidV4Pattern());

    $response->assertJsonMissingPath('data.routine_id')
        ->assertJsonMissingPath('data.days.0.cycle_id')
        ->assertJsonMissingPath('data.days.0.cycle');

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(429)
        ->assertJsonPath('data.code', 'RATE_LIMIT_EXCEPTION');
});

it('rate-limits per user, not globally', function () {
    $routine = loadedRoutine($this->user);
    $other = User::factory()->create();
    AthleteProfile::factory()->for($other)->create();
    $otherRoutine = loadedRoutine($other);

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);
    $this->actingAs($other)->postJson("/api/v1/routines/{$otherRoutine->uuid}/cycles")->assertStatus(201);
});
