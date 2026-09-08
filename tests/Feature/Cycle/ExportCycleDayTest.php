<?php

use App\Enums\Recommendation\RecommendationAction;
use App\Enums\Recommendation\RecommendationStatus;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Str;

// docs/plans/export-training-day-csv-spec.md §8, TC-1..TC-24.
// (TC-25 — the route inherits the root security scheme — lives in
// tests/Feature/Auth/DocsSecurityTest.php.)

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function exportUrl(Routine $routine, CycleDay $day): string
{
    return "/api/v1/routines/{$routine->uuid}/cycle-days/{$day->uuid}/export";
}

/**
 * A routine owned by the given user with one `active` cycle and one day.
 *
 * @param  array<string, mixed>  $cycleAttributes
 * @param  array<string, mixed>  $dayAttributes
 * @return array{0: Routine, 1: CycleDay}
 */
function routineWithActiveDay(User $user, array $cycleAttributes = [], array $dayAttributes = []): array
{
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create($cycleAttributes);
    $day = CycleDay::factory()->for($cycle)->create($dayAttributes);

    return [$routine, $day];
}

// ---------------------------------------------------------------------------
// Feature — happy path & format
// ---------------------------------------------------------------------------

// TC-1
it('exports a valid day as a CSV attachment scoped to that day only', function () {
    $routine = Routine::factory()->for($this->user)->create(['name' => 'Volumen invierno']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 3]);

    $dayOne = CycleDay::factory()->for($cycle)->create(['order' => 1, 'label' => 'Empuje']);
    $press = Exercise::factory()->create(['name' => 'Press banca']);
    DayExercise::factory()->for($dayOne)->for($press)->create(['order' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($press)->create([
        'status' => RecommendationStatus::Active,
        'explanation' => 'solo dia 1',
    ]);

    $dayThree = CycleDay::factory()->for($cycle)->create([
        'order' => 3,
        'label' => 'Piernas',
        'focus_muscle_groups' => ['quads', 'glutes'],
    ]);
    DayExercise::factory()->for($dayThree)->for(Exercise::factory()->create(['name' => 'Sentadilla']))
        ->create(['order' => 1, 'sets' => 4]);
    DayExercise::factory()->for($dayThree)->for(Exercise::factory()->create(['name' => 'Zancada']))
        ->create(['order' => 2, 'sets' => 3]);

    $response = $this->actingAs($this->user)->get(exportUrl($routine, $dayThree));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('volumen-invierno-ciclo-3-dia-3-piernas.csv');

    $body = $response->streamedContent();

    expect($body)->toContain('# rutina: Volumen invierno | ciclo 3 | dia 3 (Piernas) | foco: quads, glutes')
        ->and($body)->toContain('exercise,set_number,prescribed_weight_kg,prescribed_reps,prescribed_rpe,rest_seconds,recommended_weight_kg,recommended_action,weight_kg,reps,rpe,note')
        ->and($body)->not->toContain('Press banca')
        ->and($body)->not->toContain('solo dia 1');

    $rows = csvDataRows($body);

    expect($rows)->toHaveCount(7)
        ->and(array_column($rows, 1))->toBe(['1', '2', '3', '4', '1', '2', '3'])
        ->and(collect($rows)->every(fn (array $r): bool => $r[8] === '' && $r[9] === '' && $r[10] === '' && $r[11] === ''))->toBeTrue();
});

// TC-2
it('fills the recommended columns and the # line for an exercise with an active recommendation', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['sets' => 2]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($squat)->create([
        'status' => RecommendationStatus::Active,
        'target_weight_kg' => 102.5,
        'action' => RecommendationAction::AdvanceWeight,
        'explanation' => 'Subiste las 4x5 a RPE 7',
    ]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect(collect($rows)->every(fn (array $r): bool => $r[6] === '102.5' && $r[7] === 'advance_weight'))->toBeTrue()
        ->and($body)->toContain('Recomendacion: advance_weight — Subiste las 4x5 a RPE 7');
});

// TC-3
it('still lists an exercise with no recommendation, with empty recommended cells', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'Remo']))->create(['sets' => 2]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect(collect($rows)->every(fn (array $r): bool => $r[6] === '' && $r[7] === ''))->toBeTrue()
        ->and($body)->not->toContain('Recomendacion:');
});

// TC-4
it('ignores an applied (non-active) recommendation', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['sets' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($squat)->create([
        'status' => RecommendationStatus::Applied,
    ]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect($rows[0][6])->toBe('')->and($rows[0][7])->toBe('')
        ->and($body)->not->toContain('Recomendacion:');
});

// TC-5
it('renders prescribed_reps as a range only when rep_min differs from rep_max', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'A']))
        ->create(['order' => 1, 'sets' => 1, 'rep_min' => 5, 'rep_max' => 5]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))
        ->create(['order' => 2, 'sets' => 1, 'rep_min' => 6, 'rep_max' => 12]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect($rows[0][3])->toBe('5')
        ->and($rows[1][3])->toBe('6-12')
        ->and($body)->toContain('x5')
        ->and($body)->toContain('x6-12');
});

// TC-6
it('leaves prescribed weight and rpe blank and trims the fragment when null', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)->create([
        'target_weight_kg' => null,
        'target_rpe' => null,
        'rest_seconds' => 90,
        'sets' => 1,
    ]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect($rows[0][2])->toBe('')
        ->and($rows[0][4])->toBe('')
        ->and($rows[0][5])->toBe('90')
        ->and($body)->toContain('descanso 90s')
        ->and($body)->not->toContain('@ ')
        ->and($body)->not->toContain('RPE');
});

// TC-7
it('omits the split rationale line when the cycle has none', function () {
    [$routine, $day] = routineWithActiveDay($this->user, ['split_rationale' => null]);
    DayExercise::factory()->for($day)->create();

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();

    expect($body)->not->toContain('# racional del split:')
        ->and($body)->toContain('# rutina:');
});

// TC-8
it('collapses multi-line rationale and explanation onto one # line', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['rationale' => "uno\ndos", 'sets' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($squat)->create([
        'status' => RecommendationStatus::Active,
        'explanation' => "tres\ncuatro",
        'action' => RecommendationAction::Hold,
    ]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();

    expect($body)->toContain('Racional: uno dos')
        ->and($body)->toContain('— tres cuatro');

    expect(csvDataRows($body)[0])->toHaveCount(12);
});

// TC-9
it('orders rows by day_exercise.order then set_number', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))->create(['order' => 2, 'sets' => 1]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'A']))->create(['order' => 1, 'sets' => 1]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();

    expect(array_column(csvDataRows($body), 0))->toBe(['A', 'B']);
});

// TC-10
it('quotes a field that needs it and keeps the row at 12 fields', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)
        ->for(Exercise::factory()->create(['name' => 'Press, inclinado "30°"']))
        ->create(['sets' => 1]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect($rows[0])->toHaveCount(12)
        ->and($rows[0][0])->toBe('Press, inclinado "30°"');
});

// TC-11
it('writes the # block in Spanish regardless of the app locale', function () {
    app()->setLocale('en');

    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)->create();

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();

    expect($body)->toContain('rutina:')
        ->and($body)->toContain('foco:')
        ->and($body)->toContain('racional del split:')
        ->and($body)->toContain('prescripcion:')
        ->and($body)->toContain('descanso');
});

// TC-12
it('exports a day with zero exercises', function () {
    [$routine, $day] = routineWithActiveDay($this->user);

    $response = $this->actingAs($this->user)->get(exportUrl($routine, $day));
    $body = $response->assertOk()->streamedContent();

    expect($body)->toContain('# rutina:')
        ->and(csvDataRows($body))->toBeEmpty();
});

// TC-13
it('gives the same exercise prescribed twice a continuous set_number', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['order' => 1, 'sets' => 4, 'target_weight_kg' => 100]);
    DayExercise::factory()->for($day)->for($squat)->create(['order' => 2, 'sets' => 3, 'target_weight_kg' => 80]);

    $body = $this->actingAs($this->user)->get(exportUrl($routine, $day))->streamedContent();
    $rows = csvDataRows($body);

    expect($rows)->toHaveCount(7)
        ->and(array_column($rows, 0))->toBe(array_fill(0, 7, 'Sentadilla'))
        ->and(array_column($rows, 1))->toBe(['1', '2', '3', '4', '5', '6', '7'])
        ->and(array_column($rows, 2))->toBe(['100', '100', '100', '100', '80', '80', '80']);
});

// ---------------------------------------------------------------------------
// Feature — authorization & errors
// ---------------------------------------------------------------------------

// TC-14
it('rejects a {day} that belongs to another routine', function () {
    [$routine] = routineWithActiveDay($this->user);

    $otherUser = User::factory()->create();
    [, $foreignDay] = routineWithActiveDay($otherUser);

    $this->actingAs($this->user)
        ->getJson("/api/v1/routines/{$routine->uuid}/cycle-days/{$foreignDay->uuid}/export")
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
});

// TC-15
it('rejects a {day} from a non-active cycle of the same routine', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $oldCycle = Cycle::factory()->completed()->for($routine)->create(['sequence_number' => 1]);
    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create();

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $oldDay))
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
});

// TC-16
it('rejects a routine whose current cycle is not active', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $cycle = Cycle::factory()->generating()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $day))
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'ROUTINE_HAS_NO_ACTIVE_CYCLE');
});

// TC-17
it('rejects an archived routine with ROUTINE_HAS_NO_ACTIVE_CYCLE', function () {
    $routine = Routine::factory()->for($this->user)->archived()->create();
    $cycle = Cycle::factory()->completed()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $day))
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'ROUTINE_HAS_NO_ACTIVE_CYCLE');
});

// TC-18
it('returns 403 for a routine owned by another user', function () {
    $otherUser = User::factory()->create();
    [$routine, $day] = routineWithActiveDay($otherUser);

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $day))
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');
});

// TC-19
it('returns 404 for an unknown routine uuid', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/routines/'.Str::uuid()->toString().'/cycle-days/'.Str::uuid()->toString().'/export')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-20
it('returns 404 for an unknown day uuid', function () {
    $routine = Routine::factory()->for($this->user)->create();
    Cycle::factory()->active()->for($routine)->create();

    $this->actingAs($this->user)
        ->getJson("/api/v1/routines/{$routine->uuid}/cycle-days/".Str::uuid()->toString().'/export')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-21
it('returns 404 for a non-uuid path segment', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/routines/not-a-uuid/cycle-days/also-bad/export')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-22
it('rejects an unauthenticated request', function () {
    [$routine, $day] = routineWithActiveDay($this->user);

    $this->getJson(exportUrl($routine, $day))
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-23
it('renders without tripping strict-mode lazy loading', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    $withRec = Exercise::factory()->create(['name' => 'A']);
    DayExercise::factory()->for($day)->for($withRec)->create(['order' => 1, 'sets' => 2]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))->create(['order' => 2, 'sets' => 2]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'C']))->create(['order' => 3, 'sets' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($withRec)->create([
        'status' => RecommendationStatus::Active,
    ]);

    $this->actingAs($this->user)->get(exportUrl($routine, $day))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

// ---------------------------------------------------------------------------
// Feature — docs
// ---------------------------------------------------------------------------

// TC-24
it('documents the export response as text/csv, not application/json', function () {
    $spec = app(Generator::class)();

    $content = $spec['paths']['/api/v1/routines/{routine}/cycle-days/{day}/export']['get']['responses'][200]['content'];

    expect($content)->toHaveKey('text/csv')
        ->and($content)->not->toHaveKey('application/json');
});
