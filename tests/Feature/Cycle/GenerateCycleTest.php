<?php

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Enums\Recommendation\RecommendationStatus;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;

// TC-1..TC-17 — generate-next-cycle-spec.md §8

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    fakeCyclePlanner();
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

// TC-1
it('returns 201 with a real 5-day active cycle', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $routine->cycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles");

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.sequence_number', 2)
        ->assertJsonCount(5, 'data.days');

    expect($response->json('data.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.split_rationale'))->not->toBe('')
        ->and($response->json('data.generated_at'))->toMatch(iso8601Pattern());

    $this->assertDatabaseHas('cycles', [
        'routine_id' => $routine->id,
        'sequence_number' => 2,
        'status' => 'active',
    ]);
});

// TC-2
it('rolls the outgoing cycle to completed when all 5 days were trained', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $outgoingCycle = $routine->cycle;
    $outgoingCycle->cycleDays->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($outgoingCycle->refresh()->status->value)->toBe('completed')
        ->and($outgoingCycle->completed_at)->not->toBeNull();
});

// TC-3
it('rolls the outgoing cycle to incomplete when fewer than 5 days were trained', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $outgoingCycle = $routine->cycle;
    $outgoingCycle->cycleDays->take(3)->each(fn (CycleDay $day) => trainDay($routine, $this->user, $day));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($outgoingCycle->refresh()->status->value)->toBe('incomplete')
        ->and($outgoingCycle->completed_at)->not->toBeNull();
});

// TC-4
it('still rolls over when the outgoing week was never trained at all', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $outgoingCycle = $routine->cycle;

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($outgoingCycle->refresh()->status->value)->toBe('incomplete');
    $this->assertDatabaseMissing('exercise_recommendations', ['status' => 'applied']);
});

// TC-5
it('marks recommendations for trained exercises applied, leaves untrained ones active', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $days = $routine->cycle->cycleDays;

    $trainedExerciseOne = $days[0]->dayExercises->first()->exercise;
    $trainedExerciseTwo = $days[1]->dayExercises->first()->exercise;
    $untrainedExercise = $days[2]->dayExercises->first()->exercise;

    trainDay($routine, $this->user, $days[0]);
    trainDay($routine, $this->user, $days[1]);

    $trainedRecommendationOne = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($trainedExerciseOne)->create();
    $trainedRecommendationTwo = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($trainedExerciseTwo)->create();
    $untrainedRecommendation = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($untrainedExercise)->create();

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($trainedRecommendationOne->refresh()->status)->toBe(RecommendationStatus::Applied)
        ->and($trainedRecommendationTwo->refresh()->status)->toBe(RecommendationStatus::Applied)
        ->and($untrainedRecommendation->refresh()->status)->toBe(RecommendationStatus::Active);
});

// TC-6
it('leaves an already-applied recommendation for a trained exercise applied', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $day = $routine->cycle->cycleDays->first();
    $exercise = $day->dayExercises->first()->exercise;

    trainDay($routine, $this->user, $day);
    $recommendation = ExerciseRecommendation::factory()->applied()->for($this->user)->for($routine)->for($exercise)->create();

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    expect($recommendation->refresh()->status)->toBe(RecommendationStatus::Applied);
});

// TC-7
it('prompts the planner with the profile, routine goal/hint, active recommendations and progression summary', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $routine->update(['goal' => 'strength', 'hint' => 'Focus on compound lifts.']);

    $day = $routine->cycle->cycleDays->first();
    $exercise = $day->dayExercises->first()->exercise;
    trainDay($routine, $this->user, $day);

    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($exercise)->create([
        'action' => 'advance_weight',
        'explanation' => 'Distinctive explanation marker.',
    ]);

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    CyclePlannerAgent::assertPrompted(function ($prompt) use ($exercise): bool {
        $text = $prompt->prompt;

        return str_contains($text, 'strength')
            && str_contains($text, 'Focus on compound lifts.')
            && str_contains($text, 'advance_weight')
            && str_contains($text, $exercise->name)
            && str_contains($text, 'Active recommendations')
            && str_contains($text, 'Progression summary');
    });
});

// TC-8
it('guides the planner to hold on an unperformed exercise', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $untrainedExercise = $routine->cycle->cycleDays->first()->dayExercises->first()->exercise;

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    CyclePlannerAgent::assertPrompted(function ($prompt) use ($untrainedExercise): bool {
        $text = $prompt->prompt;

        return str_contains($text, $untrainedExercise->name)
            && str_contains($text, 'Progression summary')
            && str_contains($text, 'performed: no')
            && str_contains($text, 'no data — keep the current target');
    });
});

// TC-9
it('returns 502 and persists nothing when the planner throws', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $outgoingCycle = $routine->cycle;
    CyclePlannerAgent::fake(fn () => throw new RuntimeException('provider unavailable'));

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(502)
        ->assertJsonPath('data.code', 'AI_GENERATION_FAILED');

    $this->assertDatabaseCount('cycles', 1);
    expect($outgoingCycle->refresh()->status->value)->toBe('active');
});

// TC-10
it('returns 502 and persists nothing for a malformed plan', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $payload = cyclePlanPayload();
    $payload['days'] = array_slice($payload['days'], 0, 4);
    CyclePlannerAgent::fake([$payload]);

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(502);

    $this->assertDatabaseCount('cycles', 1);
    $this->assertDatabaseCount('cycle_days', 5);
});

// TC-11
it('returns 409 ROUTINE_NOT_ACTIVE for an archived routine', function () {
    $routine = Routine::factory()->archived()->for($this->user)->create();
    Cycle::factory()->completed()->for($routine)->create();

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'ROUTINE_NOT_ACTIVE');

    $this->assertDatabaseCount('cycles', 1);
    CyclePlannerAgent::assertNeverPrompted();
});

// TC-12
it('rate-limits a second call within a minute', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(429)
        ->assertJsonPath('data.code', 'RATE_LIMIT_EXCEPTION');
});

// TC-13
it('rate-limits per user, not globally', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');
    $other = User::factory()->create();
    AthleteProfile::factory()->for($other)->create();
    $otherRoutine = trainingRoutineWithCycle($other)->load('cycle.cycleDays.dayExercises.exercise');

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")->assertStatus(201);
    $this->actingAs($other)->postJson("/api/v1/routines/{$otherRoutine->uuid}/cycles")->assertStatus(201);
});

// TC-14
it('returns 403 for another user\'s routine', function () {
    $other = User::factory()->create();
    AthleteProfile::factory()->for($other)->create();
    $otherRoutine = trainingRoutineWithCycle($other)->load('cycle.cycleDays.dayExercises.exercise');

    $this->actingAs($this->user)->postJson("/api/v1/routines/{$otherRoutine->uuid}/cycles")
        ->assertStatus(403);

    $this->assertDatabaseCount('cycles', 1);
    CyclePlannerAgent::assertNeverPrompted();
});

// TC-15
it('returns 404 for an unknown routine uuid', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/routines/00000000-0000-4000-8000-000000000000/cycles')
        ->assertStatus(404);
});

// TC-16
it('returns 401 when unauthenticated', function () {
    $this->postJson('/api/v1/routines/00000000-0000-4000-8000-000000000000/cycles')
        ->assertStatus(401);
});

// TC-17
it('exposes uuids, never internal PKs', function () {
    $routine = trainingRoutineWithCycle($this->user)->load('cycle.cycleDays.dayExercises.exercise');

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(201);

    expect($response->json('data.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.days.0.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.days.0.exercises.0.id'))->toMatch(uuidV4Pattern());

    $response->assertJsonMissingPath('data.routine_id')
        ->assertJsonMissingPath('data.days.0.cycle_id');
});
