<?php

use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\TrainingSession;
use App\Services\Session\SessionDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// TC-9
it('throws SessionAlreadyCompletedException for a completed session', function () {
    $session = TrainingSession::factory()->make(['status' => SessionStatus::Completed]);

    expect(fn () => app(SessionDeletionService::class)->guard($session))
        ->toThrow(SessionAlreadyCompletedException::class);
});

// TC-10
it('does not throw for an in_progress session', function () {
    $session = TrainingSession::factory()->make(['status' => SessionStatus::InProgress]);

    app(SessionDeletionService::class)->guard($session);
})->throwsNoExceptions();
