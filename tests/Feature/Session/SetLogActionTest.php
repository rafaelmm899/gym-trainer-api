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

beforeEach(function () {
    $this->user = User::factory()->create();
});

function createSet(TrainingSession $session, array $payload): SetLog
{
    return app(SetLogCreateAction::class)->handle($session, LogSetData::from($payload));
}

// TC-42
it('resolves the exercise from a day_exercise_id and returns the row with the exercise loaded', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();

    $set = createSet($session, ['day_exercise_id' => $dayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8]);

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

    $set = createSet($session, ['exercise_id' => $exercise->uuid, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10]);

    expect($set->exercise_id)->toBe($exercise->id);
    $this->assertDatabaseHas('set_logs', ['session_id' => $session->id, 'exercise_id' => $exercise->id, 'set_number' => 1]);
});

// TC-44
it('rejects logging into a completed session and writes nothing', function () {
    $completed = TrainingSession::factory()->for($this->user)
        ->for(Routine::factory()->for($this->user))->completed()->create();
    $exercise = Exercise::factory()->create();

    try {
        createSet($completed, ['exercise_id' => $exercise->uuid, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10]);
        $this->fail('Expected SessionAlreadyCompletedException.');
    } catch (SessionAlreadyCompletedException $e) {
        expect($e->errorCode())->toBe('SESSION_ALREADY_COMPLETED')
            ->and($e->statusCode())->toBe(409);
    }

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-45
it('rejects a day_exercise outside the sessions training day', function () {
    $session = openPlannedSession($this->user);
    $otherDay = CycleDay::query()
        ->where('cycle_id', $session->cycleDay->cycle_id)
        ->whereKeyNot($session->cycle_day_id)
        ->first();
    $foreignDayExercise = $otherDay->dayExercises()->first();

    expect(fn () => createSet($session, [
        'day_exercise_id' => $foreignDayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8,
    ]))->toThrow(DayExerciseNotInSessionException::class);

    $this->assertDatabaseCount('set_logs', 0);
});

// TC-45
it('rejects a day_exercise_id on a free session', function () {
    $free = openFreeSession($this->user);
    $dayExercise = DayExercise::factory()->for(
        CycleDay::factory()->for(Cycle::factory()->active())
    )->create();

    expect(fn () => createSet($free, [
        'day_exercise_id' => $dayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8,
    ]))->toThrow(DayExerciseNotInSessionException::class);
});

// TC-46
it('requires set_number to be count + 1, counted per exercise', function () {
    $session = openFreeSession($this->user);
    $a = Exercise::factory()->create();
    $b = Exercise::factory()->create();

    SetLog::factory()->for($session, 'session')->for($a)->create(['set_number' => 1]);
    SetLog::factory()->for($session, 'session')->for($a)->create(['set_number' => 2]);

    // Next for A is 3; next for B is 1.
    createSet($session, ['exercise_id' => $a->uuid, 'set_number' => 3, 'weight_kg' => 60, 'reps' => 8]);
    createSet($session, ['exercise_id' => $b->uuid, 'set_number' => 1, 'weight_kg' => 40, 'reps' => 12]);

    try {
        createSet($session, ['exercise_id' => $a->uuid, 'set_number' => 3, 'weight_kg' => 60, 'reps' => 8]);
        $this->fail('Expected NonContiguousSetNumberException.');
    } catch (NonContiguousSetNumberException $e) {
        expect($e->errorCode())->toBe('NON_CONTIGUOUS_SET_NUMBER')
            ->and($e->statusCode())->toBe(409)
            ->and($e->getMessage())->toContain('4');
    }

    expect(fn () => createSet($session, ['exercise_id' => $b->uuid, 'set_number' => 3, 'weight_kg' => 40, 'reps' => 12]))
        ->toThrow(NonContiguousSetNumberException::class);
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
