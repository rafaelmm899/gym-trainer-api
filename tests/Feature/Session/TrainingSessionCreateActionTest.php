<?php

use App\Actions\Session\TrainingSessionCreateAction;
use App\Data\Session\CreateTrainingSessionData;
use App\Enums\Session\AnalysisState;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\CycleDayNotInActiveCycleException;
use App\Exceptions\Session\RoutineNotActiveException;
use App\Exceptions\Session\SessionInProgressException;
use App\Models\Cycle;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;

function runCreate(User $user, Routine $routine, ?string $day): TrainingSession
{
    return app(TrainingSessionCreateAction::class)->handle(
        $user,
        $routine,
        CreateTrainingSessionData::from($day === null ? [] : ['day' => $day]),
    );
}

// TC-22
it('opens the session and returns it with the cycle day loaded', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);
    $day = $routine->cycle->cycleDays->first();

    $session = runCreate($user, $routine, $day->uuid);

    expect($session->status)->toBe(SessionStatus::InProgress)
        ->and($session->analysis_state)->toBe(AnalysisState::Pending)
        ->and($session->started_at)->not->toBeNull()
        ->and($session->completed_at)->toBeNull()
        ->and($session->cycle_day_id)->toBe($day->id)
        ->and($session->user_id)->toBe($user->id)
        ->and($session->routine_id)->toBe($routine->id)
        ->and($session->wasRecentlyCreated)->toBeTrue()
        ->and($session->relationLoaded('cycleDay'))->toBeTrue()
        ->and($session->cycleDay->relationLoaded('dayExercises'))->toBeTrue();

    $this->assertDatabaseCount('training_sessions', 1);
});

// TC-23
it('opens a free session when no day is given', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);

    $session = runCreate($user, $routine, null);

    expect($session->cycle_day_id)->toBeNull()
        ->and($session->relationLoaded('cycleDay'))->toBeTrue()
        ->and($session->cycleDay)->toBeNull();
});

// TC-24
it('throws when the user already has an open session and writes nothing', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);
    TrainingSession::factory()->for($user)->for($routine)->create();

    $thrown = null;

    try {
        runCreate($user, $routine, null);
    } catch (SessionInProgressException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SessionInProgressException::class)
        ->and($thrown->errorCode())->toBe('SESSION_IN_PROGRESS')
        ->and($thrown->statusCode())->toBe(409);

    $this->assertDatabaseCount('training_sessions', 1);
});

// TC-25
it('throws when the routine is archived', function () {
    $user = User::factory()->create();
    $archived = Routine::factory()->for($user)->archived()->create();
    Cycle::factory()->active()->for($archived)->create();

    $thrown = null;

    try {
        runCreate($user, $archived, null);
    } catch (RoutineNotActiveException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RoutineNotActiveException::class)
        ->and($thrown->errorCode())->toBe('ROUTINE_NOT_ACTIVE');

    $this->assertDatabaseCount('training_sessions', 0);
});

// TC-26
it('throws when the day is not in the routines active cycle', function () {
    $user = User::factory()->create();
    $routine = trainingRoutineWithCycle($user);

    $foreignRoutine = trainingRoutineWithCycle(User::factory()->create());
    $foreignDay = $foreignRoutine->cycle->cycleDays->first();

    $thrown = null;

    try {
        runCreate($user, $routine, $foreignDay->uuid);
    } catch (CycleDayNotInActiveCycleException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(CycleDayNotInActiveCycleException::class)
        ->and($thrown->errorCode())->toBe('CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');

    $this->assertDatabaseCount('training_sessions', 0);
});
