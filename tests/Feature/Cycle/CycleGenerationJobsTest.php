<?php

use App\Ai\Agents\Cycle\CycleProgressionAgent;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Cycle\DayProgressionState;
use App\Jobs\Cycle\CycleDayProgressionJob;
use App\Jobs\Cycle\CycleFinalizeJob;

// async-cycle-generation-spec.md — the queued jobs around the actions

it('retries a day a minute later and gives it three attempts', function () {
    $job = new CycleDayProgressionJob(routineWithGeneratingCycle()[2]->cycleDays->first());

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 60])
        ->and($job->timeout)->toBeLessThan(90);
});

it('progresses its day when it runs', function () {
    fakeCycleProgression();
    [, , $generating] = routineWithGeneratingCycle();

    CycleDayProgressionJob::dispatchSync($generating->cycleDays->firstWhere('order', 4));

    expect($generating->cycleDays->firstWhere('order', 4)->refresh()->progression_state)->toBe(DayProgressionState::Ai);
});

it('marks the cycle failed when a day job fails for good', function () {
    [, , $generating] = routineWithGeneratingCycle();

    (new CycleDayProgressionJob($generating->cycleDays->first()))->failed(new RuntimeException('db down'));

    expect($generating->refresh()->status)->toBe(CycleStatus::Failed);
});

it('finalizes the cycle when its job runs and marks it failed when that job fails', function () {
    [, , $generating] = routineWithGeneratingCycle();
    $generating->cycleDays()->update(['progression_state' => DayProgressionState::Ai]);

    CycleFinalizeJob::dispatchSync($generating);
    expect($generating->refresh()->status)->toBe(CycleStatus::Active);

    $generating->update(['status' => CycleStatus::Generating]);
    (new CycleFinalizeJob($generating))->failed(new RuntimeException('boom'));
    expect($generating->refresh()->status)->toBe(CycleStatus::Failed);
});

it('runs the whole chain to an active cycle through the endpoint on the sync queue', function () {
    fakeCycleProgression();
    [$routine, $outgoing, $generating] = routineWithGeneratingCycle();
    $generating->delete();

    $this->withHeader('Origin', config('app.url'))
        ->actingAs($routine->user)
        ->postJson("/api/v1/routines/{$routine->uuid}/cycles")
        ->assertStatus(202);

    $next = $routine->cycles()->where('sequence_number', 2)->sole();

    expect($next->status)->toBe(CycleStatus::Active)
        ->and($next->cycleDays()->where('progression_state', 'ai')->count())->toBe(5)
        ->and($outgoing->refresh()->status)->toBe(CycleStatus::Completed);
    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '- day 5, exercise 1'));
});
