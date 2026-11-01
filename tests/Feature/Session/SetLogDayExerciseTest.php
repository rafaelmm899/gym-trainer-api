<?php

use App\Enums\Session\AnalysisState;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\SetLog;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

// docs/plans/session-redesign-data-spec.md §8, TC-24..TC-28.

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

// TC-24
it('stores the declared day_exercise_id when logging against a prescription', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();

    $response = $this->actingAs($this->user)->postJson("/api/v1/sessions/{$session->uuid}/sets", [
        'day_exercise_id' => $dayExercise->uuid,
        'set_number' => 1,
        'weight_kg' => 80,
        'reps' => 8,
    ])->assertCreated()->assertJsonPath('data.day_exercise_id', $dayExercise->uuid);

    $this->assertDatabaseHas('set_logs', [
        'uuid' => $response->json('data.id'),
        'day_exercise_id' => $dayExercise->id,
    ]);
});

// TC-25
it('leaves day_exercise_id null for an off-plan set', function () {
    $session = openFreeSession($this->user);

    $this->actingAs($this->user)->postJson("/api/v1/sessions/{$session->uuid}/sets", [
        'exercise_id' => Exercise::factory()->create()->uuid,
        'set_number' => 1,
        'weight_kg' => 60,
        'reps' => 10,
    ])->assertCreated()->assertJsonPath('data.day_exercise_id', null);
});

// TC-26
it('keeps day_exercise_id when a set is updated', function () {
    $session = openPlannedSession($this->user);
    $dayExercise = $session->cycleDay->dayExercises->first();
    $set = SetLog::factory()->for($session, 'session')->for($dayExercise->exercise)->create([
        'day_exercise_id' => $dayExercise->id,
        'set_number' => 1,
    ]);

    $this->actingAs($this->user)->putJson("/api/v1/sessions/{$session->uuid}/sets/{$set->uuid}", [
        'weight_kg' => 82.5,
        'reps' => 7,
    ])->assertOk()->assertJsonPath('data.day_exercise_id', $dayExercise->uuid);

    expect($set->fresh()->day_exercise_id)->toBe($dayExercise->id);
});

// TC-27
it('returns each set with the prescription it was logged against, without N+1', function () {
    $session = openPlannedSession($this->user);
    $first = $session->cycleDay->dayExercises->first();
    $second = DayExercise::factory()->for($session->cycleDay)->for($first->exercise)->create(['order' => 99]);

    SetLog::factory()->for($session, 'session')->for($first->exercise)->create([
        'day_exercise_id' => $first->id,
        'set_number' => 1,
    ]);
    $this->actingAs($this->user);
    $queriesWithOneSet = countQueries(fn () => $this->getJson("/api/v1/sessions/{$session->uuid}")->assertOk());

    SetLog::factory()->for($session, 'session')->for($first->exercise)->create([
        'day_exercise_id' => $second->id,
        'set_number' => 2,
    ]);
    $detail = $this->getJson("/api/v1/sessions/{$session->uuid}")->assertOk();
    $queriesWithTwoSets = countQueries(fn () => $this->getJson("/api/v1/sessions/{$session->uuid}")->assertOk());

    expect(collect($detail->json('data.sets'))->pluck('day_exercise_id')->all())->toBe([$first->uuid, $second->uuid])
        ->and($queriesWithTwoSets)->toBe($queriesWithOneSet);
});

// TC-28
it('stores day_exercise_id for every set created by a workbook import', function () {
    Bus::fake([SessionAnalysisJob::class]);

    [$routine, $day] = importRoutineWithActiveDay($this->user);
    $squat = prescribeExercise($day, 'Sentadilla', 1);
    $lunge = prescribeExercise($day, 'Zancada', 2);

    $this->actingAs($this->user)->post(
        "/api/v1/routines/{$routine->uuid}/cycle-days/{$day->uuid}/import",
        ['file' => buildXlsxUploadedFile([
            importRow('Sentadilla', 100, 8),
            importRow('Sentadilla', 102.5, 7),
            importRow('Zancada', 40, 10),
        ])],
    )->assertCreated()->assertJsonPath('data.analysis_state', AnalysisState::Pending->value);

    $byExercise = SetLog::query()->get()->groupBy('exercise_id');

    expect($byExercise[$squat->exercise_id]->pluck('day_exercise_id')->unique()->all())->toBe([$squat->id])
        ->and($byExercise[$lunge->exercise_id]->pluck('day_exercise_id')->unique()->all())->toBe([$lunge->id]);
});
