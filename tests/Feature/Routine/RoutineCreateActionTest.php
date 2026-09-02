<?php

use App\Actions\Routine\RoutineCreateAction;
use App\Data\Routine\RoutineData;
use App\Enums\Routine\RoutineStatus;
use App\Enums\Shared\Goal;
use App\Exceptions\Profile\ProfileIncompleteException;
use App\Jobs\Cycle\GenerateCycleJob;
use App\Models\AthleteProfile;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
});

// TC-19
it('archives the incumbent, inserts the new active routine and queues generation', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $action = app(RoutineCreateAction::class);

    $first = $action->handle($user, new RoutineData(
        name: 'Winter Volume',
        goal: Goal::Hypertrophy,
        hint: null,
    ));

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($first->status)->toBe(RoutineStatus::Active)
        ->and($first->days_per_cycle)->toBe(5);
    $this->assertDatabaseCount('routines', 1);
    $this->assertDatabaseHas('routines', [
        'user_id' => $user->id,
        'status' => 'active',
        'days_per_cycle' => 5,
    ]);
    Bus::assertDispatchedTimes(GenerateCycleJob::class, 1);

    $second = $action->handle($user, new RoutineData(
        name: 'Spring Cut',
        goal: Goal::FatLoss,
        hint: null,
    ));

    expect($first->refresh()->status)->toBe(RoutineStatus::Archived)
        ->and($first->archived_at)->not->toBeNull()
        ->and($second->status)->toBe(RoutineStatus::Active);
    $this->assertDatabaseCount('routines', 2);
    expect(
        $user->routines()->where('status', RoutineStatus::Active)->count()
    )->toBe(1);
    Bus::assertDispatchedTimes(GenerateCycleJob::class, 2);
});

// TC-20
it('throws and writes nothing when the user has no athlete profile', function () {
    $user = User::factory()->create();
    $action = app(RoutineCreateAction::class);

    expect(fn () => $action->handle($user, new RoutineData(
        name: 'Winter Volume',
        goal: Goal::Hypertrophy,
        hint: null,
    )))->toThrow(ProfileIncompleteException::class);

    $this->assertDatabaseCount('routines', 0);
    Bus::assertNothingDispatched();
});
