<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Services\Recommendation\RecommendationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// `listCurrentForRoutine()` queries cycles/cycle_days/day_exercises/exercise_recommendations.
uses(TestCase::class, RefreshDatabase::class);

it('includes a recommendation for an exercise still in the current cycle', function () {
    $routine = Routine::factory()->create();
    $exercise = Exercise::factory()->create();

    $cycle = Cycle::factory()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();
    DayExercise::factory()->for($day, 'cycleDay')->for($exercise)->create();

    $recommendation = ExerciseRecommendation::factory()->for($routine)->for($exercise)->create();

    $result = app(RecommendationCatalogService::class)->listCurrentForRoutine($routine);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($recommendation->id);
});

it('excludes a recommendation for an exercise no longer in the current cycle', function () {
    $routine = Routine::factory()->create();
    $droppedExercise = Exercise::factory()->create();
    $keptExercise = Exercise::factory()->create();

    $firstCycle = Cycle::factory()->for($routine)->create(['sequence_number' => 1]);
    $firstDay = CycleDay::factory()->for($firstCycle)->create();
    DayExercise::factory()->for($firstDay, 'cycleDay')->for($droppedExercise)->create();

    $currentCycle = Cycle::factory()->for($routine)->create(['sequence_number' => 2]);
    $currentDay = CycleDay::factory()->for($currentCycle)->create();
    DayExercise::factory()->for($currentDay, 'cycleDay')->for($keptExercise)->create();

    ExerciseRecommendation::factory()->for($routine)->for($droppedExercise)->create();
    $keptRecommendation = ExerciseRecommendation::factory()->for($routine)->for($keptExercise)->create();

    $result = app(RecommendationCatalogService::class)->listCurrentForRoutine($routine);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($keptRecommendation->id);
});

it('returns an empty collection when the routine has no current cycle', function () {
    $routine = Routine::factory()->create();

    $result = app(RecommendationCatalogService::class)->listCurrentForRoutine($routine);

    expect($result)->toHaveCount(0);
});
