<?php

use App\Actions\Session\SetLogCreateAction;
use App\Actions\Session\SetLogUpdateAction;
use App\Data\Session\LogSetData;
use App\Data\Session\UpdateSetLogData;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\DayExerciseNotInSessionException;
use App\Exceptions\Session\NonContiguousSetNumberException;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Session\SetLoggingService;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// TC-42
it('resolves the exercise from a day_exercise_id and returns the row with the exercise loaded', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();

    $set = app(SetLogCreateAction::class)->handle(
        $session,
        LogSetData::from(['day_exercise_id' => $dayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8]),
    );

    expect($set->exercise_id)->toBe($dayExercise->exercise_id)
        ->and($set->set_number)->toBe(1)
        ->and($set->wasRecentlyCreated)->toBeTrue()
        ->and($set->relationLoaded('exercise'))->toBeTrue();

    $this->assertDatabaseCount('set_logs', 1);
});

// TC-43
it('opens a free-session set from a direct exercise_id', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();

    $set = app(SetLogCreateAction::class)->handle(
        $session,
        LogSetData::from(['exercise_id' => $exercise->uuid, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10]),
    );

    expect($set->exercise_id)->toBe($exercise->id);
    $this->assertDatabaseHas('set_logs', ['session_id' => $session->id, 'exercise_id' => $exercise->id, 'set_number' => 1]);
});

// TC-44
it('guardOpen throws for a completed session and passes for an open one', function () {
    $service = app(SetLoggingService::class);

    $open = openFreeSession($this->user);
    $completed = TrainingSession::factory()->for($this->user)
        ->for(Routine::find($open->routine_id))->completed()->create();

    expect(fn () => $service->guardOpen($open))->not->toThrow(SessionAlreadyCompletedException::class);

    try {
        $service->guardOpen($completed);
        $this->fail('Expected SessionAlreadyCompletedException.');
    } catch (SessionAlreadyCompletedException $e) {
        expect($e->errorCode())->toBe('SESSION_ALREADY_COMPLETED')
            ->and($e->statusCode())->toBe(409);
    }
});

// TC-45
it('rejects a day_exercise outside the sessions training day', function () {
    $session = openPlannedSession($this->user);
    $otherDay = CycleDay::query()
        ->where('cycle_id', $session->cycleDay->cycle_id)
        ->whereKeyNot($session->cycle_day_id)
        ->first();
    $foreignDayExercise = $otherDay->dayExercises()->first();

    expect(fn () => app(SetLoggingService::class)->resolveExercise(
        $session,
        LogSetData::from(['day_exercise_id' => $foreignDayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8]),
    ))->toThrow(DayExerciseNotInSessionException::class);
});

// TC-45
it('rejects a day_exercise_id on a free session', function () {
    $free = openFreeSession($this->user);
    $dayExercise = DayExercise::factory()->for(
        CycleDay::factory()->for(Cycle::factory()->active())
    )->create();

    expect(fn () => app(SetLoggingService::class)->resolveExercise(
        $free,
        LogSetData::from(['day_exercise_id' => $dayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8]),
    ))->toThrow(DayExerciseNotInSessionException::class);
});

// TC-46
it('computes the expected set_number as count + 1, per exercise', function () {
    $session = openFreeSession($this->user);
    $a = Exercise::factory()->create();
    $b = Exercise::factory()->create();

    SetLog::factory()->for($session, 'session')->for($a)->create(['set_number' => 1]);
    SetLog::factory()->for($session, 'session')->for($a)->create(['set_number' => 2]);

    $service = app(SetLoggingService::class);

    expect(fn () => $service->guardContiguousSetNumber($session, $a, 3))->not->toThrow(NonContiguousSetNumberException::class);
    expect(fn () => $service->guardContiguousSetNumber($session, $b, 1))->not->toThrow(NonContiguousSetNumberException::class);

    try {
        $service->guardContiguousSetNumber($session, $a, 2);
        $this->fail('Expected NonContiguousSetNumberException.');
    } catch (NonContiguousSetNumberException $e) {
        expect($e->errorCode())->toBe('NON_CONTIGUOUS_SET_NUMBER')
            ->and($e->statusCode())->toBe(409)
            ->and($e->getMessage())->toContain('3');
    }

    expect(fn () => $service->guardContiguousSetNumber($session, $b, 2))->toThrow(NonContiguousSetNumberException::class);
});

// TC-47
it('SetLogUpdateAction guards the session then updates only the mutable fields', function () {
    $session = openFreeSession($this->user);
    $set = SetLog::factory()->for($session, 'session')->for(Exercise::factory())
        ->create(['set_number' => 1, 'weight_kg' => 80, 'reps' => 8, 'rpe' => null, 'note' => null]);

    $updated = app(SetLogUpdateAction::class)->handle(
        $session,
        $set,
        UpdateSetLogData::from(['weight_kg' => 82.5, 'reps' => 7]),
    );

    expect((float) $updated->weight_kg)->toBe(82.5)
        ->and($updated->reps)->toBe(7)
        ->and($updated->set_number)->toBe(1)
        ->and($updated->relationLoaded('exercise'))->toBeTrue();

    $session->update(['status' => SessionStatus::Completed, 'completed_at' => now()]);

    expect(fn () => app(SetLogUpdateAction::class)->handle(
        $session->fresh(),
        $set->fresh(),
        UpdateSetLogData::from(['weight_kg' => 100, 'reps' => 3]),
    ))->toThrow(SessionAlreadyCompletedException::class);

    expect((float) $set->fresh()->weight_kg)->toBe(82.5);
});
