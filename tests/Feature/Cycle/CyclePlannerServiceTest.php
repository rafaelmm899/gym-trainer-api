<?php

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Data\Cycle\CyclePlanData;
use App\Data\Cycle\CyclePlanDayData;
use App\Data\Cycle\CyclePlanExerciseData;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Models\AthleteProfile;
use App\Services\Cycle\CyclePlannerService;

// TC-24
it('maps a well-formed structured response into the DTO tree', function () {
    fakeCyclePlanner();
    $profile = AthleteProfile::factory()->create();

    $plan = app(CyclePlannerService::class)->planFirstCycle($profile, Goal::Hypertrophy, 'PPL');

    expect($plan)->toBeInstanceOf(CyclePlanData::class)
        ->and($plan->splitRationale)->toBeString()->not->toBe('')
        ->and($plan->days)->toHaveCount(5)
        ->and($plan->days[0])->toBeInstanceOf(CyclePlanDayData::class);

    $exercise = $plan->days[0]->exercises[0];
    expect($exercise)->toBeInstanceOf(CyclePlanExerciseData::class)
        ->and($exercise->name)->toBe('Barbell Bench Press')
        ->and($exercise->sets)->toBe(3)
        ->and($exercise->repMin)->toBe(8)
        ->and($exercise->repMax)->toBe(12)
        ->and($exercise->targetWeightKg)->toBe(40.0)
        ->and($exercise->targetRpe)->toBe(7.0)
        ->and($exercise->restSeconds)->toBe(90)
        ->and($exercise->primaryMuscleGroup)->toBe('chest')
        ->and($plan->days[0]->focusMuscleGroups)->toBe(['chest', 'triceps']);
});

// TC-25
it('throws CycleGenerationException on a malformed plan shape', function (array $payload) {
    CyclePlannerAgent::fake([$payload]);
    $profile = AthleteProfile::factory()->create();

    expect(fn () => app(CyclePlannerService::class)->planFirstCycle($profile, Goal::Strength, null))
        ->toThrow(CycleGenerationException::class);
})->with([
    'four days' => [fn () => [...cyclePlanPayload(), 'days' => array_slice(cyclePlanPayload()['days'], 0, 4)]],
    'six days' => [fn () => [...cyclePlanPayload(), 'days' => [...cyclePlanPayload()['days'], cyclePlanPayload()['days'][0]]]],
    'empty day' => [function () {
        $payload = cyclePlanPayload();
        $payload['days'][4]['exercises'] = [];

        return $payload;
    }],
    'reps inverted' => [fn () => cyclePlanPayload(['days' => [['exercises' => [['rep_min' => 12, 'rep_max' => 8]]]]])],
    'zero sets' => [fn () => cyclePlanPayload(['days' => [['exercises' => [['sets' => 0]]]]])],
    'null weight' => [fn () => cyclePlanPayload(['days' => [['exercises' => [['target_weight_kg' => null]]]]])],
    'unknown muscle group' => [fn () => cyclePlanPayload(['days' => [['focus_muscle_groups' => ['pecs', 'triceps']]]])],
    'missing split rationale' => [fn () => cyclePlanPayload(['split_rationale' => ''])],
]);

// TC-26
it('wraps a planner-thrown exception in CycleGenerationException', function () {
    CyclePlannerAgent::fake(fn () => throw new RuntimeException('timeout'));
    $profile = AthleteProfile::factory()->create();

    $thrown = null;

    try {
        app(CyclePlannerService::class)->planFirstCycle($profile, Goal::Strength, null);
    } catch (CycleGenerationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(CycleGenerationException::class)
        ->and($thrown->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getPrevious()->getMessage())->toBe('timeout');
});

// TC-27a
it('puts the experience-level exercise range into the prompt', function () {
    fakeCyclePlanner();
    $profile = AthleteProfile::factory()->create(['experience_level' => ExperienceLevel::Intermediate->value]);

    app(CyclePlannerService::class)->planFirstCycle($profile, Goal::Hypertrophy, null);

    CyclePlannerAgent::assertPrompted(
        fn ($prompt): bool => str_contains($prompt->prompt, 'between 4 and 6 exercises')
    );
});

// TC-27b
it('derives the exercise range from config per experience level', function (string $level, string $range, int $perDay, bool $ok) {
    config(["training.cycle.exercises_per_day.{$level}" => $range]);

    $payload = cyclePlanPayload();
    foreach ($payload['days'] as &$day) {
        $day['exercises'] = array_slice(
            array_pad($day['exercises'], $perDay, $day['exercises'][0]),
            0,
            $perDay,
        );
    }
    unset($day);
    CyclePlannerAgent::fake([$payload]);

    $profile = AthleteProfile::factory()->create(['experience_level' => $level]);
    $call = fn () => app(CyclePlannerService::class)->planFirstCycle($profile, Goal::Strength, null);

    $ok
        ? expect($call())->toBeInstanceOf(CyclePlanData::class)
        : expect($call)->toThrow(CycleGenerationException::class);
})->with([
    'beginner within range' => ['beginner', '3-5', 4, true],
    'beginner over configured max' => ['beginner', '3-4', 5, false],
    'advanced under configured min' => ['advanced', '6-8', 5, false],
    'advanced within widened range' => ['advanced', '4-8', 4, true],
]);

// TC-27
it('builds the prompt from every profile field plus the routine goal and hint', function () {
    fakeCyclePlanner();
    $profile = AthleteProfile::factory()->create([
        'experience_level' => ExperienceLevel::Advanced->value,
        'days_per_week' => 4,
        'session_minutes' => 75,
        'notes' => 'Prefers free weights, bad left knee.',
    ]);

    app(CyclePlannerService::class)->planFirstCycle($profile, Goal::Hypertrophy, 'dumbbells only');

    CyclePlannerAgent::assertPrompted(function ($prompt): bool {
        $text = $prompt->prompt;

        return str_contains($text, 'advanced')
            && str_contains($text, '4')
            && str_contains($text, '75')
            && str_contains($text, 'Prefers free weights, bad left knee.')
            && str_contains($text, 'hypertrophy')
            && str_contains($text, 'dumbbells only')
            && str_contains($text, '5 training days')
            && str_contains($text, 'kilograms');
    });
});
