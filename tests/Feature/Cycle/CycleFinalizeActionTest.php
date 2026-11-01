<?php

use App\Actions\Cycle\CycleFinalizeAction;
use App\Enums\Cycle\CycleStatus;
use App\Enums\Cycle\DayProgressionState;
use App\Enums\Recommendation\RecommendationStatus;
use App\Models\ExerciseRecommendation;
use App\Models\TrainingSession;

// async-cycle-generation-spec.md — the last step of cycle N+1

it('activates the new cycle, rolls the outgoing one over and applies the trained recommendations', function () {
    [$routine, $outgoing, $generating] = routineWithGeneratingCycle();
    $generating->cycleDays->each(fn ($day) => $day->update(['progression_state' => DayProgressionState::Ai]));
    $trained = ExerciseRecommendation::factory()->for($routine->user)->for($routine)->for($outgoing->cycleDays->first()->dayExercises->first()->exercise)->create();

    app(CycleFinalizeAction::class)->handle($generating);

    expect($generating->refresh())
        ->status->toBe(CycleStatus::Active)
        ->split_rationale->toBe("Progressed 5 of 5 trained days with the AI; the rest used your session recommendations or kept last week's prescription.")
        ->activated_at->not->toBeNull()
        ->and($outgoing->refresh()->status)->toBe(CycleStatus::Completed)
        ->and($outgoing->completed_at)->not->toBeNull()
        ->and($trained->refresh()->status)->toBe(RecommendationStatus::Applied);
});

it('counts the days that fell back separately', function () {
    [, , $generating] = routineWithGeneratingCycle();
    $generating->cycleDays->each(fn ($day) => $day->update(['progression_state' => DayProgressionState::Fallback]));
    $generating->cycleDays->first()->update(['progression_state' => DayProgressionState::Ai]);

    app(CycleFinalizeAction::class)->handle($generating);

    expect($generating->refresh()->split_rationale)->toContain('Progressed 1 of 5 trained days with the AI');
});

it('says nothing was trained when no day needed the AI', function () {
    [, , $generating] = routineWithGeneratingCycle();
    $generating->cycleDays()->update(['progression_state' => DayProgressionState::Unchanged]);

    app(CycleFinalizeAction::class)->handle($generating);

    expect($generating->refresh()->split_rationale)->toBe('No exercise was trained last cycle; the prescription is unchanged.');
});

it('rolls an outgoing cycle with untrained days to incomplete', function () {
    [, $outgoing, $generating] = routineWithGeneratingCycle();
    TrainingSession::query()->where('cycle_day_id', $outgoing->cycleDays->last()->id)->delete();

    app(CycleFinalizeAction::class)->handle($generating);

    expect($outgoing->refresh()->status)->toBe(CycleStatus::Incomplete);
});

it('is a no-op once the cycle is no longer generating', function () {
    [, $outgoing, $generating] = routineWithGeneratingCycle();
    $generating->update(['status' => CycleStatus::Failed]);

    app(CycleFinalizeAction::class)->handle($generating);

    expect($generating->refresh()->status)->toBe(CycleStatus::Failed)
        ->and($outgoing->refresh()->status)->toBe(CycleStatus::Active);
});
