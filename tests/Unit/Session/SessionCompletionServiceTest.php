<?php

use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Exceptions\Session\SessionHasNoSetsException;
use App\Models\Exercise;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Session\SessionCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unlike the pure in-memory `TrainingSessionPolicyTest`, `guard()` queries the
// `sets()` relation, so this file (only) needs the app + a real DB — bound
// here rather than in `tests/Pest.php`, which reserves that setup for the
// `Feature` suite.
uses(TestCase::class, RefreshDatabase::class);

// TC-14
it('throws SessionAlreadyCompletedException for a completed session, before checking sets', function () {
    $session = TrainingSession::factory()->make(['status' => SessionStatus::Completed]);

    expect(fn () => app(SessionCompletionService::class)->guard($session))
        ->toThrow(SessionAlreadyCompletedException::class);
});

// TC-15
it('throws SessionHasNoSetsException for an in_progress session with zero sets', function () {
    $session = openFreeSession(User::factory()->create());

    expect(fn () => app(SessionCompletionService::class)->guard($session))
        ->toThrow(SessionHasNoSetsException::class);
});

// TC-16
it('does not throw for an in_progress session with at least one set', function () {
    $user = User::factory()->create();
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    app(SessionCompletionService::class)->guard($session);
})->throwsNoExceptions();
