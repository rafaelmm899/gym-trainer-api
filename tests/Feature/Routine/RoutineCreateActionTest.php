<?php

use App\Actions\Routine\RoutineCreateAction;
use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Data\Routine\RoutineData;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Routine\RoutineStatus;
use App\Enums\Shared\Goal;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Exceptions\Profile\ProfileIncompleteException;
use App\Models\AthleteProfile;
use App\Models\User;

beforeEach(function () {
    fakeCyclePlanner();
});

// TC-21
it('plans, archives the incumbent, inserts the routine and persists the cycle tree', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $action = app(RoutineCreateAction::class);

    $first = $action->handle($user, new RoutineData(name: 'Winter Volume', goal: Goal::Hypertrophy, hint: null));

    expect($first->status)->toBe(RoutineStatus::Active)
        ->and($first->days_per_cycle)->toBe(5)
        ->and($first->relationLoaded('cycle'))->toBeTrue()
        ->and($first->cycle->relationLoaded('cycleDays'))->toBeTrue()
        ->and($first->cycle->cycleDays->first()->relationLoaded('dayExercises'))->toBeTrue();

    $this->assertDatabaseCount('routines', 1);
    $this->assertDatabaseHas('cycles', ['routine_id' => $first->id, 'sequence_number' => 1, 'status' => 'draft']);
    $this->assertDatabaseCount('cycle_days', 5);
    $this->assertDatabaseCount('day_exercises', 10);

    $second = $action->handle($user, new RoutineData(name: 'Spring Cut', goal: Goal::FatLoss, hint: null));

    expect($first->refresh()->status)->toBe(RoutineStatus::Archived)
        ->and($first->archived_at)->not->toBeNull()
        ->and($second->status)->toBe(RoutineStatus::Active)
        ->and($second->cycle->status)->toBe(CycleStatus::Draft);

    $this->assertDatabaseCount('routines', 2);
    $this->assertDatabaseCount('cycles', 2);
    expect($user->routines()->where('status', RoutineStatus::Active)->count())->toBe(1);
});

// TC-22
it('throws CycleGenerationException and writes nothing when planning fails', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    CyclePlannerAgent::fake(fn () => throw new RuntimeException('boom'));

    $thrown = null;

    try {
        app(RoutineCreateAction::class)->handle($user, new RoutineData(name: 'X', goal: Goal::Hypertrophy, hint: null));
    } catch (CycleGenerationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(CycleGenerationException::class)
        ->and($thrown->statusCode())->toBe(502)
        ->and($thrown->errorCode())->toBe('AI_GENERATION_FAILED');

    $this->assertDatabaseCount('routines', 0);
    $this->assertDatabaseCount('cycles', 0);
});

// TC-23
it('throws ProfileIncompleteException before planning when the user has no profile', function () {
    $user = User::factory()->create();

    expect(fn () => app(RoutineCreateAction::class)->handle($user, new RoutineData(name: 'X', goal: Goal::Hypertrophy, hint: null)))
        ->toThrow(ProfileIncompleteException::class);

    CyclePlannerAgent::assertNeverPrompted();
    $this->assertDatabaseCount('routines', 0);
});
