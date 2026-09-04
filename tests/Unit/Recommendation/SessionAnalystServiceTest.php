<?php

use App\Ai\Agents\Recommendation\SessionAnalystAgent;
use App\Exceptions\Recommendation\SessionAnalysisException;
use App\Models\Exercise;
use App\Models\SetLog;
use App\Models\User;
use App\Services\Recommendation\SessionAnalystService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// `analyze()` queries `sets`/`cycleDay`/`exercise_recommendations`, so this
// file (only) needs the app + a real DB — matches `SessionCompletionServiceTest`.
uses(TestCase::class, RefreshDatabase::class);

// TC-11
it('throws SessionAnalysisException when the agent call itself fails', function () {
    SessionAnalystAgent::fake(fn () => throw new RuntimeException('boom'));

    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    expect(fn () => app(SessionAnalystService::class)->analyze($session))
        ->toThrow(SessionAnalysisException::class);
});

// TC-12
it('throws SessionAnalysisException when the response count does not match the exercises trained', function () {
    SessionAnalystAgent::fake(fn () => ['recommendations' => []]);

    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    expect(fn () => app(SessionAnalystService::class)->analyze($session))
        ->toThrow(SessionAnalysisException::class);
});

// TC-13
it('throws SessionAnalysisException when target_rep_min exceeds target_rep_max', function () {
    SessionAnalystAgent::fake(fn () => ['recommendations' => [[
        'target_weight_kg' => 20.0,
        'target_sets' => 3,
        'target_rep_min' => 12,
        'target_rep_max' => 8,
        'action' => 'hold',
        'explanation' => 'x',
    ]]]);

    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    expect(fn () => app(SessionAnalystService::class)->analyze($session))
        ->toThrow(SessionAnalysisException::class);
});

// TC-14
it('throws SessionAnalysisException for an unknown action value', function () {
    SessionAnalystAgent::fake(fn () => ['recommendations' => [[
        'target_weight_kg' => 20.0,
        'target_sets' => 3,
        'target_rep_min' => 8,
        'target_rep_max' => 10,
        'action' => 'not_a_real_action',
        'explanation' => 'x',
    ]]]);

    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    expect(fn () => app(SessionAnalystService::class)->analyze($session))
        ->toThrow(SessionAnalysisException::class);
});

// TC-15
it('returns one recommendation per distinct exercise, exerciseId taken from the session, not the AI response', function () {
    fakeSessionAnalyst();

    $user = User::factory()->create();
    $session = openFreeSession($user);
    $exerciseA = Exercise::factory()->create();
    $exerciseB = Exercise::factory()->create();

    SetLog::factory()->for($session, 'session')->for($exerciseA, 'exercise')->create(['set_number' => 1]);
    SetLog::factory()->for($session, 'session')->for($exerciseA, 'exercise')->create(['set_number' => 2]);
    SetLog::factory()->for($session, 'session')->for($exerciseB, 'exercise')->create(['set_number' => 1]);

    $recommendations = app(SessionAnalystService::class)->analyze($session);

    expect($recommendations)->toHaveCount(2)
        ->and(collect($recommendations)->pluck('exerciseId')->sort()->values()->all())
        ->toBe(collect([$exerciseA->id, $exerciseB->id])->sort()->values()->all());
});
