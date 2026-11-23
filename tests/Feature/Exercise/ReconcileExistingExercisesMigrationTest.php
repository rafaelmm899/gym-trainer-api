<?php

use App\Enums\Shared\MuscleGroup;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Catalogue spec §8, reconciliation migration TC-25..TC-34.

/**
 * The approved mapping, keyed by existing exercise id.
 *
 * @return array<int, array{id: int, name: string, action: string, external_id: ?string, merge_into_id: ?int}>
 */
function reconciliationMapping(): array
{
    $entries = json_decode(file_get_contents(database_path('catalog/exercise-reconciliation.json')), true);

    return collect($entries)->keyBy('id')->all();
}

/**
 * The 75 production exercises as they were: same ids and names, and the slug the
 * old rule produced (a non-breaking hyphen was dropped).
 */
function seedProductionExercises(): void
{
    foreach (reconciliationMapping() as $entry) {
        Exercise::factory()->create([
            'id' => $entry['id'],
            'name' => $entry['name'],
            'slug' => Str::slug(Str::ascii($entry['name'])),
            'primary_muscle_group' => MuscleGroup::Chest,
            'created_by_ai' => true,
        ]);
    }
}

/**
 * One prescription, one set and one recommendation on `$exerciseId`, each in its
 * own cycle day, session and routine so nothing collides.
 */
function giveHistoryTo(int $exerciseId): void
{
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $day = CycleDay::factory()->for(Cycle::factory()->for($routine)->create())->create();
    $session = TrainingSession::factory()->for($user)->for($routine)->create();

    DayExercise::factory()->create(['cycle_day_id' => $day->id, 'exercise_id' => $exerciseId]);
    SetLog::factory()->create(['session_id' => $session->id, 'exercise_id' => $exerciseId]);
    ExerciseRecommendation::factory()->create(['user_id' => $user->id, 'routine_id' => $routine->id, 'exercise_id' => $exerciseId]);
}

function runReconciliation(): void
{
    (require database_path('migrations/2026_11_23_120100_reconcile_existing_exercises.php'))->up();
}

/**
 * @return array<string, int>
 */
function historyTotals(): array
{
    return [
        'exercises' => Exercise::query()->count(),
        'day_exercises' => DB::table('day_exercises')->count(),
        'set_logs' => DB::table('set_logs')->count(),
        'recommendations' => DB::table('exercise_recommendations')->count(),
    ];
}

/**
 * @return list<array{0: int, 1: int}> [loser, winner]
 */
function mergePairs(): array
{
    return collect(reconciliationMapping())
        ->where('action', 'merge')
        ->map(fn (array $entry): array => [$entry['id'], $entry['merge_into_id']])
        ->values()
        ->all();
}

it('ships a mapping of 75 exercises that adds up', function () {
    $actions = collect(reconciliationMapping())->countBy('action');

    expect(reconciliationMapping())->toHaveCount(75)
        ->and($actions->all())->toBe(['enrich' => 56, 'manual' => 6, 'merge' => 11, 'untouched' => 2]);
});

// TC-25
it('gives the enrich rows their external id and leaves identity alone', function () {
    seedProductionExercises();
    $before = Exercise::query()->get()->keyBy('id');

    runReconciliation();

    foreach (reconciliationMapping() as $entry) {
        if ($entry['action'] === 'merge') {
            continue;
        }

        $row = Exercise::query()->find($entry['id']);

        expect($row->external_id)->toBe($entry['action'] === 'enrich' ? $entry['external_id'] : null)
            ->and($row->uuid)->toBe($before[$entry['id']]->uuid)
            ->and($row->name)->toBe($before[$entry['id']]->name)
            ->and($row->primary_muscle_group)->toBe(MuscleGroup::Chest);
    }

    expect(Exercise::query()->whereNotNull('external_id')->count())->toBe(56);
});

// TC-26
it('merges the duplicates and keeps all their history', function () {
    seedProductionExercises();

    foreach (collect(mergePairs())->flatten()->unique() as $exerciseId) {
        giveHistoryTo($exerciseId);
    }

    $before = historyTotals();
    $setsOfFour = DB::table('set_logs')->whereIn('exercise_id', [4, 43, 68])->count();

    runReconciliation();

    $after = historyTotals();
    $losers = array_column(mergePairs(), 0);

    expect($after['exercises'])->toBe(64)
        ->and($after['day_exercises'])->toBe($before['day_exercises'])
        ->and($after['set_logs'])->toBe($before['set_logs'])
        ->and($after['recommendations'])->toBe($before['recommendations'])
        ->and(Exercise::query()->whereIn('id', $losers)->count())->toBe(0)
        ->and(DB::table('day_exercises')->whereIn('exercise_id', $losers)->count())->toBe(0)
        ->and(DB::table('set_logs')->whereIn('exercise_id', $losers)->count())->toBe(0)
        ->and(DB::table('exercise_recommendations')->whereIn('exercise_id', $losers)->count())->toBe(0)
        ->and(DB::table('set_logs')->where('exercise_id', 4)->count())->toBe($setsOfFour);
});

// TC-27
it('repairs the slugs that lost their hyphen', function () {
    seedProductionExercises();

    runReconciliation();

    expect(Exercise::query()->find(11)->slug)->toBe('pull-ups')
        ->and(Exercise::query()->find(33)->slug)->toBe('t-bar-row')
        ->and(Exercise::query()->find(44)->slug)->toBe('one-arm-dumbbell-row')
        ->and(Exercise::query()->find(62))->toBeNull();
});

// TC-28
it('aborts everything when a session has sets of both exercises', function () {
    seedProductionExercises();
    $session = TrainingSession::factory()->create();
    SetLog::factory()->create(['session_id' => $session->id, 'exercise_id' => 40, 'set_number' => 1]);
    SetLog::factory()->create(['session_id' => $session->id, 'exercise_id' => 25, 'set_number' => 1]);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, 'cannot be merged');

    expect(historyTotals())->toBe($before)
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(0);
});

// TC-29
it('aborts everything when a routine has recommendations for both exercises', function () {
    seedProductionExercises();
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    ExerciseRecommendation::factory()->create(['user_id' => $user->id, 'routine_id' => $routine->id, 'exercise_id' => 64]);
    ExerciseRecommendation::factory()->create(['user_id' => $user->id, 'routine_id' => $routine->id, 'exercise_id' => 1]);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, 'cannot be merged');

    expect(historyTotals())->toBe($before)
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(0);
});

// TC-30
it('aborts everything when a cycle day prescribes both exercises', function () {
    seedProductionExercises();
    $day = CycleDay::factory()->create();
    DayExercise::factory()->create(['cycle_day_id' => $day->id, 'exercise_id' => 67, 'order' => 1]);
    DayExercise::factory()->create(['cycle_day_id' => $day->id, 'exercise_id' => 6, 'order' => 2]);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, 'cannot be merged');

    expect(historyTotals())->toBe($before)
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(0);
});

// TC-31
it('aborts when the database matches the mapping only in part', function () {
    seedProductionExercises();
    Exercise::query()->whereKey(3)->update(['name' => 'Renamed Press']);

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, '#3');

    expect(Exercise::query()->count())->toBe(75)
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(0);
});

// TC-32
it('leaves a database that does not look like production alone', function () {
    $other = Exercise::factory()->create(['name' => 'Something Else', 'slug' => 'something-else']);
    $broken = Exercise::factory()->create(['name' => "Pull\u{2011}Ups", 'slug' => 'pullups']);
    $suffixed = Exercise::factory()->create(['name' => 'Barbell Seated Calf Raise', 'slug' => 'barbell-seated-calf-raise-1371']);

    runReconciliation();

    expect(Exercise::query()->count())->toBe(3)
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(0)
        ->and($other->fresh()->slug)->toBe('something-else')
        ->and($broken->fresh()->slug)->toBe('pull-ups')
        ->and($suffixed->fresh()->slug)->toBe('barbell-seated-calf-raise-1371');
});

// TC-33
it('keeps the identity of the manual and untouched rows', function () {
    seedProductionExercises();
    $before = Exercise::query()->get()->keyBy('id');
    $kept = collect(reconciliationMapping())->whereIn('action', ['manual', 'untouched'])->pluck('id');

    runReconciliation();

    expect($kept)->toHaveCount(8);

    foreach ($kept as $id) {
        $row = Exercise::query()->find($id);

        expect($row->uuid)->toBe($before[$id]->uuid)
            ->and($row->name)->toBe($before[$id]->name)
            ->and($row->primary_muscle_group)->toBe($before[$id]->primary_muscle_group)
            ->and($row->external_id)->toBeNull();
    }
});

// TC-34
it('aborts when a repaired slug is already taken', function () {
    Exercise::factory()->create(['name' => 'Pull-Ups', 'slug' => 'pull-ups']);
    Exercise::factory()->create(['name' => "Pull\u{2011}Ups", 'slug' => 'pullups']);

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, "'pull-ups'");

    expect(Exercise::query()->where('name', "Pull\u{2011}Ups")->sole()->slug)->toBe('pullups');
});

// Review follow-up: two exercises merged into the same winner (43 and 68 into 4)
it('aborts when two exercises merged into the same winner share a session', function () {
    seedProductionExercises();
    $session = TrainingSession::factory()->create();
    SetLog::factory()->create(['session_id' => $session->id, 'exercise_id' => 43, 'set_number' => 1]);
    SetLog::factory()->create(['session_id' => $session->id, 'exercise_id' => 68, 'set_number' => 1]);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, '#43, #68 cannot be merged into #4');

    expect(historyTotals())->toBe($before);
});

it('aborts when two exercises merged into the same winner share a recommendation scope', function () {
    seedProductionExercises();
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    ExerciseRecommendation::factory()->create(['user_id' => $user->id, 'routine_id' => $routine->id, 'exercise_id' => 43]);
    ExerciseRecommendation::factory()->create(['user_id' => $user->id, 'routine_id' => $routine->id, 'exercise_id' => 68]);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, 'cannot be merged into #4');

    expect(historyTotals())->toBe($before);
});

it('aborts when two exercises merged into the same winner are prescribed on one cycle day', function () {
    seedProductionExercises();
    $day = CycleDay::factory()->create();
    DayExercise::factory()->create(['cycle_day_id' => $day->id, 'exercise_id' => 43, 'order' => 1]);
    DayExercise::factory()->create(['cycle_day_id' => $day->id, 'exercise_id' => 68, 'order' => 2]);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, 'cannot be merged into #4');

    expect(historyTotals())->toBe($before);
});

// Review follow-up: a failure after the merges and the enrichment already wrote
it('rolls back the merges and the enrichment when the slug repair fails', function () {
    seedProductionExercises();
    giveHistoryTo(40);
    Exercise::factory()->create(['id' => 900, 'name' => 'Something Else', 'slug' => 'pull-ups']);
    $before = historyTotals();

    expect(fn () => runReconciliation())->toThrow(RuntimeException::class, "'pull-ups'");

    expect(historyTotals())->toBe($before)
        ->and(Exercise::query()->whereKey(40)->exists())->toBeTrue()
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(0)
        ->and(DB::table('day_exercises')->where('exercise_id', 40)->count())->toBe(1)
        ->and(Exercise::query()->find(11)->slug)->toBe('pullups');
});
