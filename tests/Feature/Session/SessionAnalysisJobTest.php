<?php

use App\Ai\Agents\Recommendation\SessionAnalystAgent;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function analysisCompleteUrl(TrainingSession $session): string
{
    return "/api/v1/sessions/{$session->uuid}/complete";
}

// TC-1
it('produces one recommendation for a session with one trained exercise', function () {
    fakeSessionAnalyst();

    $exercise = Exercise::factory()->create();
    $session = openFreeSession($this->user);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    $response = $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), []);

    $response->assertOk();
    $this->assertDatabaseCount('exercise_recommendations', 1);
    $this->assertDatabaseHas('exercise_recommendations', [
        'user_id' => $session->user_id,
        'routine_id' => $session->routine_id,
        'exercise_id' => $exercise->id,
        'source_session_id' => $session->id,
    ]);
    expect($session->fresh()->analysis_state->value)->toBe('done');
});

// TC-2
it('persists every field of the recommendation exactly as returned by the agent', function () {
    fakeSessionAnalyst(fn () => ['recommendations' => [[
        'target_weight_kg' => 30.0,
        'target_sets' => 5,
        'target_rep_min' => 6,
        'target_rep_max' => 8,
        'action' => 'add_set',
        'explanation' => 'Solid session — add a set.',
    ]]]);

    $exercise = Exercise::factory()->create();
    $session = openFreeSession($this->user);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), [])->assertOk();

    $recommendation = ExerciseRecommendation::query()->where('exercise_id', $exercise->id)->firstOrFail();

    expect((float) $recommendation->target_weight_kg)->toBe(30.0)
        ->and($recommendation->target_sets)->toBe(5)
        ->and($recommendation->target_rep_min)->toBe(6)
        ->and($recommendation->target_rep_max)->toBe(8)
        ->and($recommendation->action->value)->toBe('add_set')
        ->and($recommendation->explanation)->toBe('Solid session — add a set.');
});

// TC-3
it('analyzes every exercise trained in one session with a single agent call', function () {
    fakeSessionAnalyst();

    $session = openFreeSession($this->user);
    $exercises = Exercise::factory()->count(3)->create();

    foreach ($exercises as $exercise) {
        SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);
    }

    $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), [])->assertOk();

    $this->assertDatabaseCount('exercise_recommendations', 3);
    SessionAnalystAgent::assertPrompted(fn ($prompt) => substr_count($prompt->prompt, 'Exercise:') === 3);
});

// TC-4
it('replaces the previous recommendation for the same exercise in the same routine', function () {
    $exercise = Exercise::factory()->create();
    $routine = Routine::factory()->for($this->user)->create();

    ExerciseRecommendation::factory()->create([
        'user_id' => $this->user->id,
        'routine_id' => $routine->id,
        'exercise_id' => $exercise->id,
        'target_weight_kg' => 20.0,
    ]);

    fakeSessionAnalyst(fn () => ['recommendations' => [[
        'target_weight_kg' => 22.5,
        'target_sets' => 4,
        'target_rep_min' => 8,
        'target_rep_max' => 10,
        'action' => 'advance_weight',
        'explanation' => 'Progressing.',
    ]]]);

    $session = TrainingSession::factory()->for($this->user)->for($routine)->create();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), [])->assertOk();

    $this->assertDatabaseCount('exercise_recommendations', 1);
    expect((float) ExerciseRecommendation::query()->firstOrFail()->target_weight_kg)->toBe(22.5);
});

// TC-5
it('gives the agent the previous recommendation as the baseline, not the original cycle prescription', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();
    $exercise = $dayExercise->exercise;
    $dayExercise->update(['sets' => 4, 'rep_min' => 10, 'rep_max' => 10, 'target_weight_kg' => 20.0]);

    ExerciseRecommendation::factory()->create([
        'user_id' => $this->user->id,
        'routine_id' => $session->routine_id,
        'exercise_id' => $exercise->id,
        'target_weight_kg' => 22.5,
        'target_rep_min' => 12,
        'target_rep_max' => 12,
    ]);

    fakeSessionAnalyst();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1, 'weight_kg' => 20.0, 'reps' => 11]);

    $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), [])->assertOk();

    SessionAnalystAgent::assertPrompted(function ($prompt) {
        $text = $prompt->prompt;

        return str_contains($text, '22.50kg') && str_contains($text, '12-12 reps')
            && ! str_contains($text, 'original prescription');
    });
});

// TC-6
it('has the agent decide from sets alone when there is no recommendation and no cycle_day', function () {
    fakeSessionAnalyst();

    $exercise = Exercise::factory()->create();
    $session = openFreeSession($this->user);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), [])->assertOk();

    $this->assertDatabaseCount('exercise_recommendations', 1);
    SessionAnalystAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'No baseline'));
});

// TC-7
it('leaves a different routine\'s recommendation for the same exercise untouched', function () {
    $exercise = Exercise::factory()->create();
    $routineA = Routine::factory()->for($this->user)->archived()->create();

    ExerciseRecommendation::factory()->create([
        'user_id' => $this->user->id,
        'routine_id' => $routineA->id,
        'exercise_id' => $exercise->id,
        'target_weight_kg' => 40.0,
    ]);

    fakeSessionAnalyst();

    $routineB = Routine::factory()->for($this->user)->create();
    $sessionB = TrainingSession::factory()->for($this->user)->for($routineB)->create();
    SetLog::factory()->for($sessionB, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    $this->actingAs($this->user)->postJson(analysisCompleteUrl($sessionB), [])->assertOk();

    $this->assertDatabaseCount('exercise_recommendations', 2);
    expect((float) ExerciseRecommendation::query()->where('routine_id', $routineA->id)->firstOrFail()->target_weight_kg)->toBe(40.0);
});

// TC-8
it('never undoes the session close when the agent fails on every retry', function () {
    SessionAnalystAgent::fake(fn () => throw new RuntimeException('provider unavailable'));

    $session = openFreeSession($this->user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    $response = $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), []);

    $response->assertOk()->assertJsonPath('data.status', 'completed');
    $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'completed']);
    expect($session->fresh()->analysis_state->value)->toBe('failed');
    $this->assertDatabaseCount('exercise_recommendations', 0);
});

// TC-9
it('lands on failed, not done, when the structured response has the wrong count', function () {
    SessionAnalystAgent::fake(fn () => ['recommendations' => []]);

    $session = openFreeSession($this->user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    $response = $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), []);

    $response->assertOk();
    expect($session->fresh()->analysis_state->value)->toBe('failed');
    $this->assertDatabaseCount('exercise_recommendations', 0);
});

// TC-10
it('catches a bare PHP Error from the analyst, not just an Exception', function () {
    // TypeError extends Error, not Exception — proves SessionCloseAction's
    // dispatch-site catch (and SyncQueue's own handling) is not narrowed to
    // "normal" exceptions. This is the scenario that would have broken the
    // request under the earlier ShouldQueueAfterCommit design (see §9).
    SessionAnalystAgent::fake(fn () => throw new TypeError('unexpected shape from provider'));

    $session = openFreeSession($this->user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    $response = $this->actingAs($this->user)->postJson(analysisCompleteUrl($session), []);

    $response->assertOk()->assertJsonPath('data.status', 'completed');
    expect($session->fresh()->analysis_state->value)->toBe('failed');
});
