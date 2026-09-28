<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
});

function showSessionUrl(TrainingSession|string $session): string
{
    $id = $session instanceof TrainingSession ? $session->uuid : $session;

    return "/api/v1/sessions/{$id}";
}

// TC-1
it('shows a completed planned session in full', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    DayExercise::factory()->count(2)->for($day)
        ->sequence(['order' => 1], ['order' => 2])
        ->create();
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->planned($day)->completed()->create();
    SetLog::factory()->count(2)->for($session, 'session')
        ->sequence(['set_number' => 1], ['set_number' => 2])
        ->create();
    ExerciseRecommendation::factory()->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.id', $session->uuid)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonCount(2, 'data.cycle_day.exercises')
        ->assertJsonCount(2, 'data.sets')
        ->assertJsonCount(1, 'data.recommendations');
});

// TC-2
it('shows an in-progress session', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();
    SetLog::factory()->for($session, 'session')->create();

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonCount(1, 'data.sets')
        ->assertJsonPath('data.recommendations', []);
});

// TC-3
it('shows a free session', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    SetLog::factory()->for($session, 'session')->create();

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.cycle_day', null)
        ->assertJsonCount(1, 'data.sets');
});

// TC-4
it('returns empty arrays, not missing keys', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.sets', [])
        ->assertJsonPath('data.recommendations', []);
});

// TC-5
it('orders the sets by exercise then set number', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();
    $exerciseA = Exercise::factory()->create();
    $exerciseB = Exercise::factory()->create();
    $b1 = SetLog::factory()->for($session, 'session')->create(['exercise_id' => $exerciseB->id, 'set_number' => 1]);
    $a2 = SetLog::factory()->for($session, 'session')->create(['exercise_id' => $exerciseA->id, 'set_number' => 2]);
    $a1 = SetLog::factory()->for($session, 'session')->create(['exercise_id' => $exerciseA->id, 'set_number' => 1]);

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.sets.*.id', [$a1->uuid, $a2->uuid, $b1->uuid]);
});

// TC-6
it('gives each set its exercise and formatted numbers', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();
    SetLog::factory()->for($session, 'session')->create(['weight_kg' => 82.5, 'reps' => 8, 'rpe' => 8.5]);
    SetLog::factory()->for($session, 'session')->create(['set_number' => 2, 'rpe' => null]);

    $response = $this->actingAs($this->user)->getJson(showSessionUrl($session))->assertOk();

    $set = $response->json('data.sets.0');
    expect(array_keys($set))->toEqualCanonicalizing(['id', 'exercise', 'set_number', 'weight_kg', 'reps', 'rpe', 'note', 'created_at', 'updated_at'])
        ->and($set['id'])->toMatch(uuidV4Pattern())
        ->and($set['exercise'])->toBeArray()->toHaveKey('name')
        ->and($set['weight_kg'])->toBe(82.5)
        ->and($set['rpe'])->toBe(8.5)
        ->and($response->json('data.sets.1.rpe'))->toBeNull();
});

// TC-7
it('returns only this session sets', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();
    $other = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    $mine = SetLog::factory()->for($session, 'session')->create();
    SetLog::factory()->for($other, 'session')->create();

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.sets.*.id', [$mine->uuid]);
});

// TC-8
it('returns every recommendation sourced from the session, whatever its status', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    $other = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    $active = ExerciseRecommendation::factory()->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);
    $applied = ExerciseRecommendation::factory()->applied()->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);
    ExerciseRecommendation::factory()->for($this->user)->for($this->routine)->create(['source_session_id' => $other->id]);

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.recommendations.*.id', [$active->uuid, $applied->uuid]);
});

// TC-9
it('gives each recommendation the expected structure', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    ExerciseRecommendation::factory()->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);

    $item = $this->actingAs($this->user)->getJson(showSessionUrl($session))->assertOk()->json('data.recommendations.0');

    expect(array_keys($item))->toEqualCanonicalizing(['id', 'exercise', 'target_weight_kg', 'target_sets', 'target_rep_min', 'target_rep_max', 'action', 'explanation'])
        ->and($item['id'])->toMatch(uuidV4Pattern())
        ->and($item['exercise'])->toBeArray()
        ->and($item['action'])->toBeString();
});

// TC-10
it('includes the prescription ordered by order', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    $third = DayExercise::factory()->for($day)->create(['order' => 3]);
    $first = DayExercise::factory()->for($day)->create(['order' => 1]);
    $second = DayExercise::factory()->for($day)->create(['order' => 2]);
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->planned($day)->create();

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.cycle_day.exercises.*.id', [$first->uuid, $second->uuid, $third->uuid])
        ->assertJsonStructure(['data' => ['cycle_day' => ['exercises' => ['*' => ['name']]]]]);
});

// TC-11
it('has the expected session structure and formats', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->planned($day)->completed()->create();

    $data = $this->actingAs($this->user)->getJson(showSessionUrl($session))->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing(['id', 'status', 'analysis_state', 'note', 'perceived_effort', 'started_at', 'completed_at', 'created_at', 'updated_at', 'cycle_day', 'sets', 'recommendations'])
        ->and($data['id'])->toMatch(uuidV4Pattern())
        ->and($data['started_at'])->toMatch(iso8601Pattern())
        ->and($data['completed_at'])->toMatch(iso8601Pattern());
});

// TC-12
it('works for an archived routine', function () {
    $routine = Routine::factory()->for($this->user)->archived()->create();
    $session = TrainingSession::factory()->for($this->user)->for($routine)->completed()->create();

    $this->actingAs($this->user)->getJson(showSessionUrl($session))
        ->assertOk()
        ->assertJsonPath('data.id', $session->uuid);
});

// TC-13
it('does not lazy load', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    DayExercise::factory()->count(3)->for($day)->sequence(['order' => 1], ['order' => 2], ['order' => 3])->create();
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->planned($day)->completed()->create();
    SetLog::factory()->count(3)->for($session, 'session')->sequence(['set_number' => 1], ['set_number' => 2], ['set_number' => 3])->create();
    ExerciseRecommendation::factory()->count(2)->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);

    $this->actingAs($this->user)->getJson(showSessionUrl($session))->assertOk();
});

// TC-14
it('leaves the list payload unchanged', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    SetLog::factory()->for($session, 'session')->create();
    ExerciseRecommendation::factory()->for($this->user)->for($this->routine)->create(['source_session_id' => $session->id]);

    $item = $this->actingAs($this->user)->getJson("/api/v1/routines/{$this->routine->uuid}/sessions")->assertOk()->json('data.0');

    expect($item)->not->toHaveKeys(['sets', 'recommendations']);
});

// TC-15
it('requires authentication', function () {
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();

    $this->getJson(showSessionUrl($session))
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-16
it('forbids another user session', function () {
    $stranger = User::factory()->create();
    $session = TrainingSession::factory()->for($this->user)->for($this->routine)->create();
    SetLog::factory()->for($session, 'session')->create();

    $this->actingAs($stranger)->getJson(showSessionUrl($session))
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')
        ->assertJsonMissingPath('data.sets');
});

// TC-17
it('returns 404 for an unknown or malformed session id', function (string $id) {
    $this->actingAs($this->user)->getJson(showSessionUrl($id))
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
})->with([
    'unknown uuid' => fn () => (string) Str::uuid(),
    'not a uuid' => 'not-a-uuid',
]);
