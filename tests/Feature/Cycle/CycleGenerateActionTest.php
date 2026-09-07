<?php

use App\Actions\Cycle\CycleGenerateAction;
use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Enums\Cycle\CycleStatus;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Exceptions\Cycle\RoutineNotActiveException;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;

// TC-18..TC-20 — generate-next-cycle-spec.md §8

it('plans, then creates the new cycle, persists its days, and rolls the outgoing cycle over — all in one transaction', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = Routine::factory()->for($user)->create();
    $outgoingCycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);

    $days = CycleDay::factory()->count(5)->for($outgoingCycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create();

    foreach ($days as $day) {
        DayExercise::factory()->for($day, 'cycleDay')->create();
        TrainingSession::factory()->for($routine)->completed()->planned($day)->create();
    }

    fakeCyclePlanner();

    $newCycle = app(CycleGenerateAction::class)->handle($routine);

    expect($newCycle->sequence_number)->toBe(3)
        ->and($newCycle->status)->toBe(CycleStatus::Active)
        ->and($newCycle->relationLoaded('cycleDays'))->toBeTrue()
        ->and($newCycle->cycleDays)->toHaveCount(5);

    expect($outgoingCycle->refresh()->status)->toBe(CycleStatus::Completed);
});

it('throws RoutineNotActiveException for an archived routine and writes nothing', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = Routine::factory()->archived()->for($user)->create();
    Cycle::factory()->completed()->for($routine)->create();

    fakeCyclePlanner();

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(RoutineNotActiveException::class);

    CyclePlannerAgent::assertNeverPrompted();
    $this->assertDatabaseCount('cycles', 1);
});

it('throws CycleGenerationException and writes nothing when planning fails', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = Routine::factory()->for($user)->create();
    $activeCycle = Cycle::factory()->active()->for($routine)->create();

    CyclePlannerAgent::fake(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(CycleGenerationException::class);

    expect($activeCycle->refresh()->status)->toBe(CycleStatus::Active);
    $this->assertDatabaseCount('cycles', 1);
});
