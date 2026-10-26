<?php

use App\Actions\Cycle\CycleDayProgressAction;
use App\Ai\Agents\Cycle\CycleProgressionAgent;
use App\Enums\Cycle\DayProgressionState;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;

// async-cycle-generation-spec.md — one day of a `generating` cycle N+1

/**
 * A routine whose active cycle has every day trained, plus a `generating` copy
 * of it with every day `pending` — what CycleGenerateAction leaves behind.
 *
 * @return array{Routine, Cycle, Cycle} routine, outgoing cycle, generating cycle
 */
function routineWithGeneratingCycle(): array
{
    $user = User::factory()->create();
    AthleteProfile::factory()->for($user)->create();
    $routine = trainingRoutineWithCycle($user);
    $outgoing = $routine->cycle->load('cycleDays.dayExercises.exercise');

    foreach ($outgoing->cycleDays as $day) {
        $session = TrainingSession::factory()->for($user)->for($routine)->completed()->planned($day)->create();
        SetLog::factory()->for($session, 'session')->for($day->dayExercises->first()->exercise, 'exercise')->create();
    }

    $generating = Cycle::factory()->generating()->for($routine)->create(['sequence_number' => $outgoing->sequence_number + 1]);
    app(CycleDraftService::class)->persistDays($generating, app(CyclePlannerService::class)->cloneDays($outgoing));
    $generating->cycleDays()->update(['progression_state' => DayProgressionState::Pending]);

    return [$routine, $outgoing, $generating->load('cycleDays')];
}

function dayOf(Cycle $cycle, int $order): CycleDay
{
    return $cycle->cycleDays->firstWhere('order', $order);
}

it('writes the AI progression over the day\'s cloned exercises and marks it ai', function () {
    fakeCycleProgression(['2.1' => ['sets' => 5, 'target_weight_kg' => 60.0, 'rationale' => 'Add load.']]);
    [, , $generating] = routineWithGeneratingCycle();
    $day = dayOf($generating, 2);

    app(CycleDayProgressAction::class)->handle($day, false);

    expect($day->refresh()->progression_state)->toBe(DayProgressionState::Ai)
        ->and($day->dayExercises->firstWhere('order', 1))
        ->sets->toBe(5)
        ->target_weight_kg->toBe('60.00')
        ->rationale->toBe('Add load.')
        ->and(dayOf($generating, 1)->refresh()->progression_state)->toBe(DayProgressionState::Pending);
});

it('does nothing for a day that is no longer pending', function () {
    fakeCycleProgression();
    [, , $generating] = routineWithGeneratingCycle();
    $day = dayOf($generating, 1);
    $day->update(['progression_state' => DayProgressionState::Ai]);

    app(CycleDayProgressAction::class)->handle($day, true);

    CycleProgressionAgent::assertNeverPrompted();
});

it('rethrows an AI failure while attempts remain, leaving the day pending', function () {
    CycleProgressionAgent::fake(fn () => throw new RuntimeException('Failed to validate JSON'));
    [, , $generating] = routineWithGeneratingCycle();
    $day = dayOf($generating, 1);

    expect(fn () => app(CycleDayProgressAction::class)->handle($day, false))
        ->toThrow(CycleGenerationException::class);

    expect($day->refresh()->progression_state)->toBe(DayProgressionState::Pending);
});

it('falls back to the session recommendation on the last attempt and carries the rest over', function () {
    CycleProgressionAgent::fake(fn () => throw new RuntimeException('rate limited'));
    [$routine, $outgoing, $generating] = routineWithGeneratingCycle();
    $day = dayOf($generating, 1);
    $slot = dayOf($outgoing, 1)->dayExercises->firstWhere('order', 1);
    $other = dayOf($outgoing, 1)->dayExercises->firstWhere('order', 2);
    ExerciseRecommendation::factory()->for($routine->user)->for($routine)->for($slot->exercise)->create([
        'target_weight_kg' => 62.5,
        'target_sets' => 5,
        'target_rep_min' => 6,
        'target_rep_max' => 8,
    ]);

    app(CycleDayProgressAction::class)->handle($day, true);

    expect($day->refresh()->progression_state)->toBe(DayProgressionState::Fallback)
        ->and($day->dayExercises->firstWhere('order', 1))->sets->toBe(5)->target_weight_kg->toBe('62.50')
        ->and($day->dayExercises->firstWhere('order', 2))->sets->toBe($other->sets);
});

it('falls back on the last attempt when the answer is unusable', function (Closure $mutate) {
    CycleProgressionAgent::fake(fn (string $prompt): array => $mutate(cycleProgressionPayload($prompt)));
    [, $outgoing, $generating] = routineWithGeneratingCycle();
    $day = dayOf($generating, 1);

    app(CycleDayProgressAction::class)->handle($day, true);

    expect($day->refresh()->progression_state)->toBe(DayProgressionState::Fallback)
        ->and($day->dayExercises->firstWhere('order', 1)->sets)->toBe(dayOf($outgoing, 1)->dayExercises->firstWhere('order', 1)->sets);
})->with([
    'missing slot' => [function (array $payload): array {
        array_pop($payload['progressions']);

        return $payload;
    }],
    'slot not asked about' => [function (array $payload): array {
        $payload['progressions'][] = [...$payload['progressions'][0], 'day' => 6];

        return $payload;
    }],
    'repeated slot' => [function (array $payload): array {
        $payload['progressions'][] = $payload['progressions'][0];

        return $payload;
    }],
    'zero sets' => [function (array $payload): array {
        $payload['progressions'][0]['sets'] = 0;

        return $payload;
    }],
    'reps inverted' => [function (array $payload): array {
        $payload['progressions'][0]['rep_min'] = 10;
        $payload['progressions'][0]['rep_max'] = 8;

        return $payload;
    }],
]);

it('only asks the AI about the trained slots of its own day', function () {
    fakeCycleProgression();
    [, , $generating] = routineWithGeneratingCycle();

    app(CycleDayProgressAction::class)->handle(dayOf($generating, 3), false);

    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '- day 3, exercise 1')
        && ! str_contains($prompt->prompt, '- day 2, exercise 1'));
});
