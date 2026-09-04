<?php

use App\Enums\Recommendation\RecommendationAction;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

/**
 * Creates a routine owned by the given user, with a current cycle whose
 * single day includes the given exercises.
 *
 * @param  array<int, Exercise>  $exercises
 */
function routineWithCurrentCycleExercises(User $user, array $exercises): Routine
{
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    foreach ($exercises as $order => $exercise) {
        DayExercise::factory()->for($day, 'cycleDay')->for($exercise)->create(['order' => $order + 1]);
    }

    return $routine;
}

// TC-1
it('lists the current recommendations for the callers routine', function () {
    $exerciseA = Exercise::factory()->create(['name' => 'Press banca']);
    $exerciseB = Exercise::factory()->create(['name' => 'Peso muerto']);
    $routine = routineWithCurrentCycleExercises($this->user, [$exerciseA, $exerciseB]);

    $recommendationA = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($exerciseA)
        ->create(['action' => RecommendationAction::AdvanceWeight]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($exerciseB)->create();

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}/recommendations")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'exercise' => ['id', 'name', 'slug', 'primary_muscle_group'],
                    'target_weight_kg', 'target_sets', 'target_rep_min', 'target_rep_max',
                    'action', 'explanation',
                ],
            ],
        ])
        // "Peso muerto" sorts before "Press banca" alphabetically.
        ->assertJsonPath('data.1.id', $recommendationA->uuid)
        ->assertJsonPath('data.1.exercise.id', $exerciseA->uuid)
        ->assertJsonPath('data.1.action', RecommendationAction::AdvanceWeight->value);
});

// TC-2
it('excludes a recommendation for an exercise no longer in the current cycle', function () {
    $droppedExercise = Exercise::factory()->create();
    $keptExercise = Exercise::factory()->create();
    $routine = Routine::factory()->for($this->user)->create();

    $firstCycle = Cycle::factory()->for($routine)->create(['sequence_number' => 1]);
    $firstDay = CycleDay::factory()->for($firstCycle)->create();
    DayExercise::factory()->for($firstDay, 'cycleDay')->for($droppedExercise)->create();

    $currentCycle = Cycle::factory()->for($routine)->create(['sequence_number' => 2]);
    $currentDay = CycleDay::factory()->for($currentCycle)->create();
    DayExercise::factory()->for($currentDay, 'cycleDay')->for($keptExercise)->create();

    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($droppedExercise)->create();
    $keptRecommendation = ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($keptExercise)->create();

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}/recommendations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $keptRecommendation->uuid);
});

// TC-3
it('returns an empty list when the current cycles exercises have no recommendation yet', function () {
    $exercise = Exercise::factory()->create();
    $routine = routineWithCurrentCycleExercises($this->user, [$exercise]);

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}/recommendations")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// TC-4
it('orders recommendations alphabetically by exercise name', function () {
    $zancada = Exercise::factory()->create(['name' => 'Zancada']);
    $sentadilla = Exercise::factory()->create(['name' => 'Sentadilla']);
    $routine = routineWithCurrentCycleExercises($this->user, [$zancada, $sentadilla]);

    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($zancada)->create();
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($sentadilla)->create();

    $response = $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}/recommendations")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('exercise.name')->all())
        ->toBe(['Sentadilla', 'Zancada']);
});

// TC-5
it('denies reading another users routine recommendations with a 403', function () {
    $other = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $otherRoutine = routineWithCurrentCycleExercises($other, [$exercise]);

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$otherRoutine->uuid}/recommendations")
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');
});

// TC-6
it('returns 404 for an unknown routine uuid', function () {
    $this->actingAs($this->user)->getJson('/api/v1/routines/'.Str::uuid()->toString().'/recommendations')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-7
it('returns 404 for a non-uuid path segment', function () {
    $this->actingAs($this->user)->getJson('/api/v1/routines/not-a-uuid/recommendations')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-8
it('rejects an unauthenticated request', function () {
    $exercise = Exercise::factory()->create();
    $routine = routineWithCurrentCycleExercises($this->user, [$exercise]);

    $this->getJson("/api/v1/routines/{$routine->uuid}/recommendations")
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-9
it('renders the resource without triggering a lazy load', function () {
    $exercise = Exercise::factory()->create();
    $routine = routineWithCurrentCycleExercises($this->user, [$exercise]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($exercise)->create();

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}/recommendations")
        ->assertOk();
});
