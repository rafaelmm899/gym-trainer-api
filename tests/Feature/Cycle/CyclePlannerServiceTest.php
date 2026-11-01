<?php

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Ai\Agents\Cycle\CycleProgressionAgent;
use App\Data\Cycle\CyclePlanData;
use App\Data\Cycle\CyclePlanDayData;
use App\Data\Cycle\CyclePlanExerciseData;
use App\Data\Cycle\ExerciseProgressionData;
use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Exceptions\Cycle\CycleGenerationException;
use App\Models\AthleteProfile;
use App\Models\Cycle;
use App\Models\DayExercise;
use App\Models\ExerciseRecommendation;
use App\Models\User;
use App\Services\Cycle\CyclePlannerService;
use Illuminate\Support\Collection;

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

/**
 * The user's active cycle with every relation `planNextCycle()` reads loaded:
 * 5 days x 3 exercises.
 */
function outgoingCycleForPlanning(): Cycle
{
    return trainingRoutineWithCycle(User::factory()->create())
        ->cycle->load('cycleDays.dayExercises.exercise');
}

/**
 * A progression summary for every distinct exercise of the cycle, `performed`
 * unless its id is in `$unperformedExerciseIds`.
 *
 * @param  list<int>  $unperformedExerciseIds
 * @return array<int, ExerciseProgressionData>
 */
function progressionSummaryFor(Cycle $cycle, array $unperformedExerciseIds = []): array
{
    return $cycle->cycleDays
        ->flatMap(fn ($day) => $day->dayExercises)
        ->unique('exercise_id')
        ->mapWithKeys(fn (DayExercise $slot): array => [
            $slot->exercise_id => new ExerciseProgressionData(
                exerciseId: $slot->exercise_id,
                exerciseName: $slot->exercise->name,
                prescribedSets: $slot->sets,
                prescribedRepMin: $slot->rep_min,
                prescribedRepMax: $slot->rep_max,
                prescribedWeightKg: $slot->target_weight_kg !== null ? (float) $slot->target_weight_kg : null,
                performed: ! in_array($slot->exercise_id, $unperformedExerciseIds, true),
                actualAvgWeightKg: 41.67,
                actualAvgReps: 9.0,
                actualMaxRpe: 8.0,
                trend: 'up',
                plateauSignal: false,
            ),
        ])
        ->all();
}

// cycle N+1, one day at a time (async-cycle-generation-spec.md)

/**
 * The performed slots of one day of the cycle, keyed like the service does.
 *
 * @param  array<int, ExerciseProgressionData>  $summary
 * @return Collection<string, DayExercise>
 */
function performedSlotsOfDay(Cycle $cycle, array $summary, int $dayOrder): Collection
{
    return app(CyclePlannerService::class)->performedSlots($cycle, $summary)
        ->filter(fn ($slot, string $key): bool => str_starts_with($key, "{$dayOrder}."));
}

/**
 * @param  array<int, ExerciseProgressionData>  $summary
 * @return array<string, CyclePlanExerciseData>
 */
function progressDayOf(Cycle $cycle, array $summary, int $dayOrder, ?Collection $recommendations = null, ?AthleteProfile $profile = null): array
{
    return app(CyclePlannerService::class)->progressDay(
        $profile ?? AthleteProfile::factory()->create(),
        Goal::Hypertrophy,
        'PPL',
        $cycle,
        performedSlotsOfDay($cycle, $summary, $dayOrder),
        $recommendations ?? new Collection,
        $summary,
    );
}

it('performedSlots() returns only the slots whose exercise was trained, keyed by day.exercise', function () {
    $cycle = outgoingCycleForPlanning();
    $unperformed = $cycle->cycleDays->firstWhere('order', 2)->dayExercises->firstWhere('order', 1);

    $slots = app(CyclePlannerService::class)->performedSlots($cycle, progressionSummaryFor($cycle, [$unperformed->exercise_id]));

    expect($slots->keys()->all())->not->toContain('2.1')
        ->and($slots->keys()->all())->toContain('1.1', '2.2', '5.3');
});

it('cloneDays() copies the outgoing days and exercises as prescribed', function () {
    $cycle = outgoingCycleForPlanning();

    $plan = app(CyclePlannerService::class)->cloneDays($cycle);

    expect($plan->days)->toHaveCount(5);

    foreach ($cycle->cycleDays->sortBy('order')->values() as $index => $day) {
        expect($plan->days[$index]->label)->toBe($day->label)
            ->and($plan->days[$index]->exercises)->toHaveCount($day->dayExercises->count());

        foreach ($day->dayExercises->sortBy('order')->values() as $position => $slot) {
            expect($plan->days[$index]->exercises[$position])
                ->exerciseId->toBe($slot->exercise_id)
                ->sets->toBe($slot->sets)
                ->repMin->toBe($slot->rep_min)
                ->repMax->toBe($slot->rep_max)
                ->targetWeightKg->toBe((float) $slot->target_weight_kg);
        }
    }
});

it('cloneDays() carries a null weight and RPE through', function () {
    $cycle = outgoingCycleForPlanning();
    $cycle->cycleDays->firstWhere('order', 1)->dayExercises->firstWhere('order', 1)->update(['target_weight_kg' => null, 'target_rpe' => null]);
    $cycle->load('cycleDays.dayExercises.exercise');

    $plan = app(CyclePlannerService::class)->cloneDays($cycle);

    expect($plan->days[0]->exercises[0])->targetWeightKg->toBeNull()->targetRpe->toBeNull();
});

// TC-16
it('progressDay() maps the AI answer onto exactly the slots of that day', function () {
    fakeCycleProgression();
    $cycle = outgoingCycleForPlanning();

    $progressions = progressDayOf($cycle, progressionSummaryFor($cycle), 2);

    expect(array_keys($progressions))->toBe(['2.1', '2.2', '2.3']);

    $slot = $cycle->cycleDays->firstWhere('order', 2)->dayExercises->firstWhere('order', 1);

    expect($progressions['2.1'])
        ->exerciseId->toBe($slot->exercise_id)
        ->name->toBe($slot->exercise->name)
        ->sets->toBe(4)
        ->repMin->toBe(8)
        ->repMax->toBe(10)
        ->targetWeightKg->toBe(45.0)
        ->targetRpe->toBe(8.0)
        ->restSeconds->toBe(120);
});

it('progressDay() prompts with only that day\'s slots', function () {
    fakeCycleProgression();
    $cycle = outgoingCycleForPlanning();

    progressDayOf($cycle, progressionSummaryFor($cycle), 2);

    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '- day 2, exercise 1')
        && str_contains($prompt->prompt, '- day 2, exercise 3')
        && ! str_contains($prompt->prompt, '- day 1, exercise 1')
        && ! str_contains($prompt->prompt, '- day 3, exercise 1'));
});

// TC-17
it('progressDay() rejects a malformed progression response', function (array $slotOverrides, array $rootOverrides) {
    fakeCycleProgression($slotOverrides, $rootOverrides);
    $cycle = outgoingCycleForPlanning();

    expect(fn () => progressDayOf($cycle, progressionSummaryFor($cycle), 1))
        ->toThrow(CycleGenerationException::class);
})->with([
    'missing progressions list' => [[], ['progressions' => null]],
    'progression that is not an object' => [[], ['progressions' => ['nope']]],
    'non-integer day' => [['1.1' => ['day' => 'first']], []],
    'non-numeric weight' => [['1.1' => ['target_weight_kg' => 'heavy']], []],
    'null weight' => [['1.1' => ['target_weight_kg' => null]], []],
    'negative weight' => [['1.1' => ['target_weight_kg' => -5.0]], []],
    'zero sets' => [['1.1' => ['sets' => 0]], []],
    'reps inverted' => [['1.1' => ['rep_min' => 10, 'rep_max' => 8]], []],
    'rpe above 10' => [['1.1' => ['target_rpe' => 11.0]], []],
    'negative rest' => [['1.1' => ['rest_seconds' => -1]], []],
    'blank rationale' => [['1.1' => ['rationale' => ' ']], []],
]);

it('progressDay() rejects a response that misses, adds or repeats a slot', function (Closure $mutate) {
    CycleProgressionAgent::fake(fn (string $prompt): array => $mutate(cycleProgressionPayload($prompt)));
    $cycle = outgoingCycleForPlanning();

    expect(fn () => progressDayOf($cycle, progressionSummaryFor($cycle), 1))
        ->toThrow(CycleGenerationException::class);
})->with([
    'missing slot' => [function (array $payload): array {
        array_pop($payload['progressions']);

        return $payload;
    }],
    'unknown exercise position' => [function (array $payload): array {
        $payload['progressions'][] = [...$payload['progressions'][0], 'exercise' => 9];

        return $payload;
    }],
    'a slot of another day' => [function (array $payload): array {
        $payload['progressions'][] = [...$payload['progressions'][0], 'day' => 2];

        return $payload;
    }],
    'repeated slot' => [function (array $payload): array {
        $payload['progressions'][] = $payload['progressions'][0];

        return $payload;
    }],
]);

// TC-19
it('progressDay() wraps a provider exception in CycleGenerationException', function () {
    CycleProgressionAgent::fake(fn () => throw new RuntimeException('boom'));
    $cycle = outgoingCycleForPlanning();

    $thrown = null;

    try {
        progressDayOf($cycle, progressionSummaryFor($cycle), 1);
    } catch (CycleGenerationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(CycleGenerationException::class)
        ->and($thrown->getPrevious()->getMessage())->toBe('boom');
});

it('progressionsFromRecommendations() applies the active recommendation and copies the rest', function () {
    $cycle = outgoingCycleForPlanning();
    $summary = progressionSummaryFor($cycle);
    $slot = $cycle->cycleDays->firstWhere('order', 1)->dayExercises->firstWhere('order', 1);
    $other = $cycle->cycleDays->firstWhere('order', 1)->dayExercises->firstWhere('order', 2);
    $recommendation = ExerciseRecommendation::factory()->for($slot->exercise)->create([
        'target_weight_kg' => 62.5,
        'target_sets' => 5,
        'target_rep_min' => 6,
        'target_rep_max' => 8,
        'explanation' => 'Beat the target twice in a row.',
    ]);

    $progressions = app(CyclePlannerService::class)->progressionsFromRecommendations(
        performedSlotsOfDay($cycle, $summary, 1),
        collect([$recommendation]),
    );

    expect($progressions['1.1'])
        ->exerciseId->toBe($slot->exercise_id)
        ->sets->toBe(5)
        ->repMin->toBe(6)
        ->repMax->toBe(8)
        ->targetWeightKg->toBe(62.5)
        ->rationale->toBe('Beat the target twice in a row.')
        ->restSeconds->toBe($slot->rest_seconds)
        ->and($progressions['1.2'])
        ->sets->toBe($other->sets)
        ->targetWeightKg->toBe((float) $other->target_weight_kg);
});

// TC-20
it('keeps the first-cycle agent free of continuation wording', function () {
    expect((new CyclePlannerAgent)->instructions())->not->toContain('continuation');
});

// TC-5, TC-6 (prompt content)
it('lists each performed slot with its prescription, actuals and active recommendation', function () {
    fakeCycleProgression();
    $cycle = outgoingCycleForPlanning();
    $slot = $cycle->cycleDays->firstWhere('order', 1)->dayExercises->firstWhere('order', 1);
    $recommendation = ExerciseRecommendation::factory()->for($cycle->routine->user)->for($cycle->routine)->for($slot->exercise)->create([
        'action' => 'advance_weight',
        'explanation' => 'Distinctive explanation marker.',
    ]);
    $profile = AthleteProfile::factory()->create(['notes' => 'Distinctive profile notes.']);

    app(CyclePlannerService::class)->progressDay(
        $profile,
        Goal::Strength,
        'Distinctive hint.',
        $cycle,
        performedSlotsOfDay($cycle, progressionSummaryFor($cycle), 1),
        new Collection([$recommendation->load('exercise')]),
        progressionSummaryFor($cycle),
    );

    CycleProgressionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Distinctive profile notes.')
        && str_contains($prompt->prompt, 'strength')
        && str_contains($prompt->prompt, 'Distinctive hint.')
        && str_contains($prompt->prompt, "- day 1, exercise 1 — {$slot->exercise->name}: prescribed {$slot->sets}x{$slot->rep_min}-{$slot->rep_max}")
        && str_contains($prompt->prompt, 'actual 41.67kg avg x 9.0 reps (max RPE 8.0)')
        && str_contains($prompt->prompt, 'recommendation: advance_weight')
        && str_contains($prompt->prompt, 'Distinctive explanation marker.')
        && str_contains($prompt->prompt, 'Return exactly one progression per listed slot'));

    expect((new CycleProgressionAgent)->instructions())
        ->toContain('Never add, remove, replace or reorder exercises');
});
