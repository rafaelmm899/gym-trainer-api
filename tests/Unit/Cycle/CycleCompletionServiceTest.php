<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\TrainingSession;
use App\Services\Cycle\CycleCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TC-27, TC-28 — generate-next-cycle-spec.md §8
uses(TestCase::class, RefreshDatabase::class);

it('is true only when every one of the cycle\'s 5 days has a completed session', function () {
    $cycle = Cycle::factory()->create();
    $days = CycleDay::factory()->count(5)->for($cycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create();

    foreach ($days as $day) {
        TrainingSession::factory()->for($cycle->routine)->completed()->planned($day)->create();
    }

    expect(app(CycleCompletionService::class)->wasCompleted($cycle))->toBeTrue();
});

it('is false when only 4 of 5 days have a completed session', function () {
    $cycle = Cycle::factory()->create();
    $days = CycleDay::factory()->count(5)->for($cycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create();

    foreach ($days->take(4) as $day) {
        TrainingSession::factory()->for($cycle->routine)->completed()->planned($day)->create();
    }

    expect(app(CycleCompletionService::class)->wasCompleted($cycle))->toBeFalse();
});

it('is false when a day\'s only session is still in_progress', function () {
    $cycle = Cycle::factory()->create();
    $days = CycleDay::factory()->count(5)->for($cycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create();

    foreach ($days->take(4) as $day) {
        TrainingSession::factory()->for($cycle->routine)->completed()->planned($day)->create();
    }
    TrainingSession::factory()->for($cycle->routine)->planned($days->last())->create();

    expect(app(CycleCompletionService::class)->wasCompleted($cycle))->toBeFalse();
});

it('does not count a free session toward any day', function () {
    $cycle = Cycle::factory()->create();
    $days = CycleDay::factory()->count(5)->for($cycle)
        ->sequence(fn ($sequence) => ['order' => $sequence->index + 1])
        ->create();

    foreach ($days->take(4) as $day) {
        TrainingSession::factory()->for($cycle->routine)->completed()->planned($day)->create();
    }
    TrainingSession::factory()->for($cycle->routine)->completed()->create();

    expect(app(CycleCompletionService::class)->wasCompleted($cycle))->toBeFalse();
});
