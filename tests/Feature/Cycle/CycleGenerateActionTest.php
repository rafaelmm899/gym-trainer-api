<?php

use App\Actions\Cycle\CycleGenerateAction;
use App\Ai\Agents\Cycle\CycleProgressionAgent;
use App\Enums\Cycle\CycleStatus;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Exceptions\Cycle\RoutineNotActiveException;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;

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
it('plans, then creates the new cycle with the same exercises, and rolls the outgoing cycle over — all in one transaction', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = routineWithTrainedCycle($user);
    $outgoingCycle = $routine->cycles()->sole()->load('cycleDays.dayExercises');

    fakeCycleProgression();

    $newCycle = app(CycleGenerateAction::class)->handle($routine);

    expect($newCycle->sequence_number)->toBe(3)
        ->and($newCycle->status)->toBe(CycleStatus::Active)
        ->and($newCycle->relationLoaded('cycleDays'))->toBeTrue()
        ->and($newCycle->cycleDays)->toHaveCount(5)
        ->and($newCycle->cycleDays->every(fn (CycleDay $day): bool => $day->relationLoaded('dayExercises')))->toBeTrue();

    foreach ($outgoingCycle->cycleDays->sortBy('order')->values() as $index => $day) {
        $newDay = $newCycle->cycleDays->sortBy('order')->values()[$index];

        expect($newDay->dayExercises->pluck('exercise_id')->all())
            ->toBe($day->dayExercises->pluck('exercise_id')->all());
    }

    expect($outgoingCycle->refresh()->status)->toBe(CycleStatus::Completed);
});

it('throws RoutineNotActiveException for an archived routine and writes nothing', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = Routine::factory()->archived()->for($user)->create();
    Cycle::factory()->completed()->for($routine)->create();

    fakeCycleProgression();

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(RoutineNotActiveException::class);

    CycleProgressionAgent::assertNeverPrompted();
    $this->assertDatabaseCount('cycles', 1);
});

it('throws CycleGenerationException and writes nothing when planning fails', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = routineWithTrainedCycle($user);
    $activeCycle = $routine->cycles()->sole();

    CycleProgressionAgent::fake(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(CycleGenerationException::class);

    expect($activeCycle->refresh()->status)->toBe(CycleStatus::Active);
    $this->assertDatabaseCount('cycles', 1);
});

// TC-15
it('throws CycleGenerationException and writes nothing when the response does not match the structure', function () {
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = routineWithTrainedCycle($user);
    $activeCycle = $routine->cycles()->sole();

    CycleProgressionAgent::fake(function (string $prompt): array {
        $payload = cycleProgressionPayload($prompt);
        array_pop($payload['progressions']);

        return $payload;
    });

    expect(fn () => app(CycleGenerateAction::class)->handle($routine))
        ->toThrow(CycleGenerationException::class);

    expect($activeCycle->refresh()->status)->toBe(CycleStatus::Active);
    $this->assertDatabaseCount('cycles', 1);
    $this->assertDatabaseCount('cycle_days', 5);
});
