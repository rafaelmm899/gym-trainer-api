<?php

use App\Actions\Cycle\CycleGenerateAction;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Cycle\DayProgressionState;
use App\Exceptions\Cycle\CycleGenerationInProgressException;
use App\Exceptions\Cycle\RoutineNotActiveException;
use App\Jobs\Cycle\CycleDayProgressionJob;
use App\Jobs\Cycle\CycleFinalizeJob;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

// TC-18..TC-20 — generate-next-cycle-spec.md §8; TC-14, TC-15 — keep-cycle-exercises-spec.md §8

/**
 * A routine whose active cycle (`sequence_number = 2`) has 5 days of one
 * exercise each, every day trained with one logged set.
 */
function routineWithTrainedCycle(User $user): Routine
{
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);

    $days = CycleDay::factory()->count(5)->for($cycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create();

    foreach ($days as $day) {
        $exercise = DayExercise::factory()->for($day, 'cycleDay')->create();
        $session = TrainingSession::factory()->for($routine)->completed()->planned($day)->create();
        SetLog::factory()->for($session, 'session')->for($exercise->exercise, 'exercise')->create();
    }

    return $routine;
}

// TC-14
it('creates the next cycle generating as a copy of the outgoing one and leaves the outgoing cycle active', function () {
    Bus::fake();
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = routineWithTrainedCycle($user);
    $outgoingCycle = $routine->cycles()->sole()->load('cycleDays.dayExercises');

    $newCycle = app(CycleGenerateAction::class)->handle($routine);

    expect($newCycle->sequence_number)->toBe(3)
        ->and($newCycle->status)->toBe(CycleStatus::Generating)
        ->and($newCycle->relationLoaded('cycleDays'))->toBeTrue()
        ->and($newCycle->cycleDays)->toHaveCount(5)
        ->and($newCycle->cycleDays->every(fn (CycleDay $day): bool => $day->progression_state === DayProgressionState::Pending))->toBeTrue();

    foreach ($outgoingCycle->cycleDays->sortBy('order')->values() as $index => $day) {
        $newDay = $newCycle->cycleDays->sortBy('order')->values()[$index];

        expect($newDay->dayExercises->pluck('exercise_id')->all())
            ->toBe($day->dayExercises->pluck('exercise_id')->all());
    }

    expect($outgoingCycle->refresh()->status)->toBe(CycleStatus::Active);
    Bus::assertChained([
        CycleDayProgressionJob::class,
        CycleDayProgressionJob::class,
        CycleDayProgressionJob::class,
        CycleDayProgressionJob::class,
        CycleDayProgressionJob::class,
        CycleFinalizeJob::class,
    ]);
});

it('throws RoutineNotActiveException for an archived routine and writes nothing', function () {
    Bus::fake();
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = Routine::factory()->archived()->for($user)->create();
    Cycle::factory()->completed()->for($routine)->create();

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(RoutineNotActiveException::class);

    Bus::assertNothingDispatched();
    $this->assertDatabaseCount('cycles', 1);
});

it('throws CycleGenerationInProgressException when the next cycle is already generating', function () {
    Bus::fake();
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = routineWithTrainedCycle($user);
    Cycle::factory()->generating()->for($routine)->create(['sequence_number' => 3]);

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(CycleGenerationInProgressException::class);

    Bus::assertNothingDispatched();
    $this->assertDatabaseCount('cycles', 2);
});
