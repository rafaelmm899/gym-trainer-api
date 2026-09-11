<?php

use App\Enums\Recommendation\RecommendationAction;
use App\Exports\Cycle\CycleDayExport;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit coverage for docs/plans/export-training-day-csv-spec.md §8, Export TC-6..TC-12.
uses(TestCase::class, RefreshDatabase::class);

/**
 * A `DayExercise` (with `exercise` loaded) and, optionally, an active
 * recommendation for the same exercise.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: DayExercise, 1: CycleDayExport}
 */
function dayExerciseExport(array $attributes = [], ?ExerciseRecommendation $recommendation = null): array
{
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    $dayExercise = DayExercise::factory()->for($exercise)->create($attributes);
    $dayExercise->setRelation('exercise', $exercise);

    $recommendations = (new Collection($recommendation === null ? [] : [$recommendation]))->keyBy('exercise_id');

    return [$dayExercise, new CycleDayExport('test.xlsx', new Collection([$dayExercise]), $recommendations)];
}

// TC-6
it('exposes the exact column header', function () {
    [, $export] = dayExerciseExport();

    expect($export->headings())->toBe([
        'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
        'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
    ]);
});

// TC-7
it('maps a prescription to one row per set with a 1-based set_number', function () {
    [$dayExercise, $export] = dayExerciseExport(['sets' => 3]);

    $rows = $export->map($dayExercise);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 0))->toBe(['Sentadilla', 'Sentadilla', 'Sentadilla'])
        ->and(array_column($rows, 1))->toBe([1, 2, 3])
        ->and(collect($rows)->every(fn (array $r): bool => $r[8] === null && $r[9] === null && $r[10] === null && $r[11] === null))
        ->toBeTrue();
});

// TC-8
it('casts the prescribed decimals to plain floats', function () {
    [$dayExercise, $export] = dayExerciseExport(['sets' => 1, 'target_weight_kg' => 100, 'target_rpe' => 8]);

    $row = $export->map($dayExercise)[0];

    expect($row[2])->toBe(100.0)
        ->and($row[4])->toBe(8.0);
});

// TC-9
it('renders prescribed_reps as an int for a point range and a string for a spread', function () {
    [$point, $pointExport] = dayExerciseExport(['sets' => 1, 'rep_min' => 5, 'rep_max' => 5]);
    [$spread, $spreadExport] = dayExerciseExport(['sets' => 1, 'rep_min' => 8, 'rep_max' => 12]);

    expect($pointExport->map($point)[0][3])->toBe(5)
        ->and($spreadExport->map($spread)[0][3])->toBe('8-12');
});

// TC-10
it('leaves prescribed weight and rpe blank when null', function () {
    [$dayExercise, $export] = dayExerciseExport(['sets' => 1, 'target_weight_kg' => null, 'target_rpe' => null]);

    $row = $export->map($dayExercise)[0];

    expect($row[2])->toBeNull()->and($row[4])->toBeNull();
});

// TC-11
it('fills the recommended columns from a matching recommendation, blank otherwise', function () {
    $exercise = Exercise::factory()->create(['name' => 'Sentadilla']);
    $dayExercise = DayExercise::factory()->for($exercise)->create(['sets' => 1]);
    $dayExercise->setRelation('exercise', $exercise);

    $recommendation = ExerciseRecommendation::factory()->for($exercise)->create([
        'target_weight_kg' => 102.5,
        'action' => RecommendationAction::AdvanceWeight,
    ]);

    $exercises = new Collection([$dayExercise]);

    $withRec = new CycleDayExport('t.xlsx', $exercises, (new Collection([$recommendation]))->keyBy('exercise_id'));
    $withoutRec = new CycleDayExport('t.xlsx', $exercises, new Collection);

    expect($withRec->map($dayExercise)[0][6])->toBe(102.5)
        ->and($withRec->map($dayExercise)[0][7])->toBe('advance_weight')
        ->and($withoutRec->map($dayExercise)[0][6])->toBeNull()
        ->and($withoutRec->map($dayExercise)[0][7])->toBeNull();
});

// TC-12
it('emits no rows for a prescription with zero sets', function () {
    [$dayExercise, $export] = dayExerciseExport(['sets' => 0]);

    expect($export->map($dayExercise))->toBe([]);
});
