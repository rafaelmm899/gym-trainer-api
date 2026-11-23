<?php

use App\Enums\Session\AnalysisState;
use App\Enums\Session\SessionStatus;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

// docs/plans/import-training-day-xlsx-spec.md §8, Feature TC-18..TC-33.

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function importUrl(Routine $routine, CycleDay $day): string
{
    return "/api/v1/routines/{$routine->uuid}/cycle-days/{$day->uuid}/import";
}

// TC-18
it('imports a fully filled day', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla', 1);
    prescribeExercise($day, 'Zancada', 2);

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8),
        importRow('Sentadilla', 102.5, 7),
        importRow('Zancada', 40, 10),
    ]);

    $response = $this->actingAs($this->user)
        ->post(importUrl($routine, $day), ['file' => $file]);

    $response->assertCreated()
        ->assertJsonPath('data.status', SessionStatus::Completed->value)
        ->assertJsonPath('data.analysis_state', AnalysisState::Pending->value);

    $session = TrainingSession::query()->where('cycle_day_id', $day->id)->sole();

    expect(SetLog::query()->where('session_id', $session->id)->count())->toBe(3);

    Bus::assertDispatched(SessionAnalysisJob::class);
});

// TC-19
it('logs only the filled exercise on a partial day', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla', 1);
    prescribeExercise($day, 'Zancada', 2);

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8),
    ]);

    $response = $this->actingAs($this->user)
        ->post(importUrl($routine, $day), ['file' => $file]);

    $response->assertCreated();

    $session = TrainingSession::query()->where('cycle_day_id', $day->id)->sole();

    expect(SetLog::query()->where('session_id', $session->id)->count())->toBe(1);
});

// TC-20
it('rejects a day that already has a completed session', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla');
    TrainingSession::factory()->for($this->user)->for($routine)->planned($day)->completed()->create();

    $file = buildXlsxUploadedFile([importRow('Sentadilla', 100, 8)]);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'CYCLE_DAY_ALREADY_COMPLETED');

    expect(TrainingSession::query()->where('cycle_day_id', $day->id)->count())->toBe(1);
});

// TC-21
it('rejects an unknown exercise name, persisting nothing', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([importRow('Press Banca', 60, 8)]);

    $response = $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION');

    $errors = $response->json('data.errors');

    expect($errors)->toHaveKey('row_2.exercise')
        ->and($errors['row_2.exercise'])->toBe(['Unknown exercise for this day.']);

    expect(TrainingSession::query()->count())->toBe(0);
});

// TC-22
it('rejects invalid row data, reporting every bad row, persisting nothing', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', -5, 8),
        importRow('Sentadilla', 100, 0),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION');

    expect($response->json('data.errors'))->toHaveKeys(['row_2.weight_kg', 'row_3.reps']);

    expect(TrainingSession::query()->count())->toBe(0);
});

// TC-23
it('rejects a missing file field', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), [])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION')
        ->assertJsonValidationErrors(['file'], 'data.errors');
});

// TC-24
it('rejects a non-xlsx upload', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);

    $file = UploadedFile::fake()->create('day.txt', 10, 'text/plain');

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION')
        ->assertJsonValidationErrors(['file'], 'data.errors');
});

// TC-25
it('rejects a workbook with zero filled rows', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([importRow('Sentadilla', null, null)]);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'SESSION_HAS_NO_SETS');

    expect(TrainingSession::query()->count())->toBe(0);
});

// TC-26
it('rejects a routine whose current cycle is not active', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $cycle = Cycle::factory()->generating()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $file = buildXlsxUploadedFile([]);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'ROUTINE_HAS_NO_ACTIVE_CYCLE');
});

// TC-27
it('rejects a day from a non-active cycle of the same routine', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $oldCycle = Cycle::factory()->completed()->for($routine)->create(['sequence_number' => 1]);
    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create();

    $file = buildXlsxUploadedFile([]);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $oldDay), ['file' => $file])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
});

// TC-28
it('returns 403 for a routine owned by another user', function () {
    [$routine, $day] = importRoutineWithActiveDay(User::factory()->create());

    $file = buildXlsxUploadedFile([]);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');
});

// TC-29
it('returns 404 for an unknown routine uuid', function () {
    $file = buildXlsxUploadedFile([]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/routines/'.Str::uuid()->toString().'/cycle-days/'.Str::uuid()->toString().'/import', ['file' => $file])
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-30
it('returns 404 for an unknown day uuid', function () {
    $routine = Routine::factory()->for($this->user)->create();
    Cycle::factory()->active()->for($routine)->create();

    $file = buildXlsxUploadedFile([]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/routines/{$routine->uuid}/cycle-days/".Str::uuid()->toString().'/import', ['file' => $file])
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-31
it('returns 404 for a non-uuid path segment', function () {
    $file = buildXlsxUploadedFile([]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/routines/not-a-uuid/cycle-days/also-bad/import', ['file' => $file])
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-32
it('rejects an unauthenticated request', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);

    $file = buildXlsxUploadedFile([]);

    $this->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-33
it('is blocked by an unrelated in-progress session', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla');
    TrainingSession::factory()->for($this->user)->for($routine)->create();

    $file = buildXlsxUploadedFile([importRow('Sentadilla', 100, 8)]);

    $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_IN_PROGRESS');

    expect(TrainingSession::query()->where('cycle_day_id', $day->id)->count())->toBe(0);
});

// TC-6
it('matches an exercise whose name has a non-breaking hyphen', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Pull-Ups', 1);

    $file = buildXlsxUploadedFile([importRow("Pull\u{2011}Ups", 20, 8)]);

    $this->actingAs($this->user)
        ->post(importUrl($routine, $day), ['file' => $file])
        ->assertCreated();

    $session = TrainingSession::query()->where('cycle_day_id', $day->id)->sole();

    expect(SetLog::query()->where('session_id', $session->id)->count())->toBe(1);
});

// Exercise id column
it('matches a row by exercise_id even when the name was edited', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    $prescription = prescribeExercise($day, 'Sentadilla', 1);

    $file = buildXlsxUploadedFile([importRow('Sentadilla (mi version)', 100, 8, exerciseId: $prescription->exercise->uuid)]);

    $this->actingAs($this->user)->post(importUrl($routine, $day), ['file' => $file])->assertCreated();

    expect(SetLog::query()->where('day_exercise_id', $prescription->id)->count())->toBe(1);
});

it('tells apart two exercises of the same name by their id', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    $first = Exercise::factory()->create(['name' => 'Barbell Seated Calf Raise', 'slug' => 'barbell-seated-calf-raise']);
    $second = Exercise::factory()->create(['name' => 'Barbell Seated Calf Raise', 'slug' => 'barbell-seated-calf-raise-1371']);
    $firstSlot = DayExercise::factory()->for($day)->for($first)->create(['order' => 1]);
    $secondSlot = DayExercise::factory()->for($day)->for($second)->create(['order' => 2]);

    $file = buildXlsxUploadedFile([
        importRow('Barbell Seated Calf Raise', 40, 12, exerciseId: $first->uuid),
        importRow('Barbell Seated Calf Raise', 50, 10, exerciseId: $second->uuid),
    ]);

    $this->actingAs($this->user)->post(importUrl($routine, $day), ['file' => $file])->assertCreated();

    expect(SetLog::query()->where('day_exercise_id', $firstSlot->id)->sole()->weight_kg)->toEqual(40)
        ->and(SetLog::query()->where('day_exercise_id', $secondSlot->id)->sole()->weight_kg)->toEqual(50);
});

it('lets the id win when it disagrees with the name', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    $squat = prescribeExercise($day, 'Sentadilla', 1);
    $lunge = prescribeExercise($day, 'Zancada', 2);

    $file = buildXlsxUploadedFile([importRow('Zancada', 100, 8, exerciseId: $squat->exercise->uuid)]);

    $this->actingAs($this->user)->post(importUrl($routine, $day), ['file' => $file])->assertCreated();

    expect(SetLog::query()->where('day_exercise_id', $squat->id)->count())->toBe(1)
        ->and(SetLog::query()->where('day_exercise_id', $lunge->id)->count())->toBe(0);
});

it('accepts a row with an id and no name', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    $squat = prescribeExercise($day, 'Sentadilla', 1);

    $file = buildXlsxUploadedFile([importRow(null, 100, 8, exerciseId: $squat->exercise->uuid)]);

    $this->actingAs($this->user)->post(importUrl($routine, $day), ['file' => $file])->assertCreated();

    expect(SetLog::query()->where('day_exercise_id', $squat->id)->count())->toBe(1);
});

it('rejects an id that is not prescribed on that day, even if the name matches', function () {
    [$routine, $day] = importRoutineWithActiveDay($this->user);
    prescribeExercise($day, 'Sentadilla', 1);
    $elsewhere = Exercise::factory()->create(['name' => 'Press Banca', 'slug' => 'press-banca']);

    $file = buildXlsxUploadedFile([importRow('Sentadilla', 100, 8, exerciseId: $elsewhere->uuid)]);

    $response = $this->actingAs($this->user)
        ->postJson(importUrl($routine, $day), ['file' => $file])
        ->assertStatus(422);

    expect($response->json('data.errors')['row_2.exercise'])->toBe(['Unknown exercise for this day.'])
        ->and(SetLog::query()->count())->toBe(0);
});

it('still imports a file exported before the exercise_id column existed', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    $squat = prescribeExercise($day, 'Sentadilla', 1);

    $file = buildXlsxUploadedFile([importRow('Sentadilla', 100, 8)], withExerciseId: false);

    $this->actingAs($this->user)->post(importUrl($routine, $day), ['file' => $file])->assertCreated();

    expect(SetLog::query()->where('day_exercise_id', $squat->id)->count())->toBe(1);
});
