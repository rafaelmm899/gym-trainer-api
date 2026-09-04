<?php

use App\Actions\Session\SessionAnalyzeAction;
use App\Ai\Agents\Recommendation\SessionAnalystAgent;
use App\Enums\Session\AnalysisState;
use App\Exceptions\Recommendation\SessionAnalysisException;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// SessionAnalysisJob::handle() writes exercise_recommendations and moves
// analysis_state, so this file (only) needs the app + a real DB — matches
// SessionCompletionServiceTest. Dispatch itself (does completing a session
// queue this job?) is covered separately by
// tests/Feature/Session/CompleteTrainingSessionTest.php via Bus::fake() — this
// file tests the job's own logic by calling handle()/failed() directly,
// mirroring how a real queue worker invokes it, without going through HTTP or
// a real dispatch.
uses(TestCase::class, RefreshDatabase::class);

function runAnalysisJob(TrainingSession $session): void
{
    (new SessionAnalysisJob($session))->handle(app(SessionAnalyzeAction::class));
}

// TC-1
it('produces one recommendation for a session with one trained exercise', function () {
    fakeSessionAnalyst();

    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    runAnalysisJob($session);

    expect($session->fresh()->analysis_state)->toBe(AnalysisState::Done);
    expect(ExerciseRecommendation::query()->count())->toBe(1);
    expect(ExerciseRecommendation::query()->firstOrFail())
        ->user_id->toBe($session->user_id)
        ->routine_id->toBe($session->routine_id)
        ->exercise_id->toBe($exercise->id)
        ->source_session_id->toBe($session->id);
});

// TC-2
it('persists every field of the recommendation exactly as returned by the agent', function () {
    SessionAnalystAgent::fake(fn () => ['recommendations' => [[
        'target_weight_kg' => 30.0,
        'target_sets' => 5,
        'target_rep_min' => 6,
        'target_rep_max' => 8,
        'action' => 'add_set',
        'explanation' => 'Solid session — add a set.',
    ]]]);

    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    runAnalysisJob($session);

    $recommendation = ExerciseRecommendation::query()->firstOrFail();

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

    $user = User::factory()->create();
    $session = openFreeSession($user);
    $exercises = Exercise::factory()->count(3)->create();

    foreach ($exercises as $exercise) {
        SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);
    }

    runAnalysisJob($session);

    expect(ExerciseRecommendation::query()->count())->toBe(3);
    SessionAnalystAgent::assertPrompted(fn ($prompt) => substr_count($prompt->prompt, 'Exercise:') === 3);
});

// TC-4
it('replaces the previous recommendation for the same exercise in the same routine', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    ExerciseRecommendation::factory()->create([
        'user_id' => $user->id,
        'routine_id' => $routine->id,
        'exercise_id' => $exercise->id,
        'target_weight_kg' => 20.0,
    ]);

    SessionAnalystAgent::fake(fn () => ['recommendations' => [[
        'target_weight_kg' => 22.5,
        'target_sets' => 4,
        'target_rep_min' => 8,
        'target_rep_max' => 10,
        'action' => 'advance_weight',
        'explanation' => 'Progressing.',
    ]]]);

    $session = TrainingSession::factory()->for($user)->for($routine)->create();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    runAnalysisJob($session);

    expect(ExerciseRecommendation::query()->count())->toBe(1);
    expect((float) ExerciseRecommendation::query()->firstOrFail()->target_weight_kg)->toBe(22.5);
});

// TC-5
it('gives the agent the previous recommendation as the baseline, not the original cycle prescription', function () {
    $user = User::factory()->create();
    $session = openPlannedSession($user);
    $dayExercise = $session->cycleDay->dayExercises->first();
    $exercise = $dayExercise->exercise;
    $dayExercise->update(['sets' => 4, 'rep_min' => 10, 'rep_max' => 10, 'target_weight_kg' => 20.0]);

    ExerciseRecommendation::factory()->create([
        'user_id' => $user->id,
        'routine_id' => $session->routine_id,
        'exercise_id' => $exercise->id,
        'target_weight_kg' => 22.5,
        'target_rep_min' => 12,
        'target_rep_max' => 12,
    ]);

    fakeSessionAnalyst();
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1, 'weight_kg' => 20.0, 'reps' => 11]);

    runAnalysisJob($session);

    SessionAnalystAgent::assertPrompted(function ($prompt) {
        $text = $prompt->prompt;

        return str_contains($text, '22.50kg') && str_contains($text, '12-12 reps')
            && ! str_contains($text, 'original prescription');
    });
});

// TC-6
it('has the agent decide from sets alone when there is no recommendation and no cycle_day', function () {
    fakeSessionAnalyst();

    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    runAnalysisJob($session);

    expect(ExerciseRecommendation::query()->count())->toBe(1);
    SessionAnalystAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'No baseline'));
});

// TC-7
it('leaves a different routine\'s recommendation for the same exercise untouched', function () {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $routineA = Routine::factory()->for($user)->archived()->create();

    ExerciseRecommendation::factory()->create([
        'user_id' => $user->id,
        'routine_id' => $routineA->id,
        'exercise_id' => $exercise->id,
        'target_weight_kg' => 40.0,
    ]);

    fakeSessionAnalyst();

    $routineB = Routine::factory()->for($user)->create();
    $sessionB = TrainingSession::factory()->for($user)->for($routineB)->create();
    SetLog::factory()->for($sessionB, 'session')->for($exercise, 'exercise')->create(['set_number' => 1]);

    runAnalysisJob($sessionB);

    expect(ExerciseRecommendation::query()->count())->toBe(2);
    expect((float) ExerciseRecommendation::query()->where('routine_id', $routineA->id)->firstOrFail()->target_weight_kg)->toBe(40.0);
});

// TC-8
it('lets an agent failure propagate out of handle(), leaving analysis_state at processing', function () {
    SessionAnalystAgent::fake(fn () => throw new RuntimeException('provider unavailable'));

    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    expect(fn () => runAnalysisJob($session))->toThrow(SessionAnalysisException::class);

    // handle() itself never sets `failed` — only the job's failed() hook does,
    // which a real queue worker calls once retries are exhausted (TC-10).
    expect($session->fresh()->analysis_state)->toBe(AnalysisState::Processing);
    expect(ExerciseRecommendation::query()->count())->toBe(0);
});

// TC-9
it('propagates a malformed structured response as SessionAnalysisException too', function () {
    SessionAnalystAgent::fake(fn () => ['recommendations' => []]);

    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1]);

    expect(fn () => runAnalysisJob($session))->toThrow(SessionAnalysisException::class);
    expect(ExerciseRecommendation::query()->count())->toBe(0);
});

// TC-10
it('failed() moves analysis_state to failed for any Throwable, not just Exception', function () {
    $user = User::factory()->create();
    $session = openFreeSession($user);

    (new SessionAnalysisJob($session))->failed(new SessionAnalysisException);
    expect($session->fresh()->analysis_state)->toBe(AnalysisState::Failed);

    // TypeError extends Error, not Exception — the job's failed(Throwable
    // $exception) signature must accept it too.
    $otherSession = openFreeSession(User::factory()->create());
    (new SessionAnalysisJob($otherSession))->failed(new TypeError('unexpected shape from provider'));
    expect($otherSession->fresh()->analysis_state)->toBe(AnalysisState::Failed);
});
