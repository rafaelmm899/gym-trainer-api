<?php

use App\Jobs\Session\SessionAnalysisJob;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function completeUrl(TrainingSession $session): string
{
    return "/api/v1/sessions/{$session->uuid}/complete";
}

function sessionWithOneSet(User $user): TrainingSession
{
    $session = openFreeSession($user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    return $session;
}

// TC-1
it('completes a session with one set and no body', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $session = sessionWithOneSet($this->user);

    $response = $this->actingAs($this->user)->postJson(completeUrl($session), []);

    $response->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.analysis_state', 'pending')
        ->assertJsonPath('data.note', null)
        ->assertJsonPath('data.perceived_effort', null);

    expect($response->json('data.completed_at'))->toMatch(iso8601Pattern());

    $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'completed']);

    Bus::assertDispatched(SessionAnalysisJob::class, fn (SessionAnalysisJob $job): bool => $job->session->is($session));
});

// TC-2
it('completes with note and perceived_effort persisted and serialised', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $session = sessionWithOneSet($this->user);

    $this->actingAs($this->user)->postJson(completeUrl($session), [
        'note' => 'felt strong today',
        'perceived_effort' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('data.note', 'felt strong today')
        ->assertJsonPath('data.perceived_effort', 4);

    $this->assertDatabaseHas('training_sessions', [
        'id' => $session->id,
        'note' => 'felt strong today',
        'perceived_effort' => 4,
    ]);
});

// TC-3
it('collapses a whitespace-only note to null', function () {
    $session = sessionWithOneSet($this->user);

    $this->actingAs($this->user)->postJson(completeUrl($session), ['note' => '   '])
        ->assertOk()
        ->assertJsonPath('data.note', null);
});

// TC-4
it('rejects an out-of-range or non-integer perceived_effort', function (mixed $value) {
    $session = sessionWithOneSet($this->user);

    $this->actingAs($this->user)->postJson(completeUrl($session), ['perceived_effort' => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors('perceived_effort', 'data.errors');

    $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'in_progress']);
})->with(['zero' => 0, 'too high' => 6, 'fractional' => 2.5]);

// TC-5
it('rejects a note longer than 1000 characters', function () {
    $session = sessionWithOneSet($this->user);

    $this->actingAs($this->user)->postJson(completeUrl($session), ['note' => str_repeat('x', 1001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('note', 'data.errors');

    $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'in_progress']);
});

// TC-6
it('rejects completing a session with zero sets logged', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $session = openFreeSession($this->user);

    $this->actingAs($this->user)->postJson(completeUrl($session), [])
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'SESSION_HAS_NO_SETS')
        ->assertJsonMissingPath('data.errors');

    $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'in_progress']);

    Bus::assertNotDispatched(SessionAnalysisJob::class);
});

// TC-7
it('rejects completing an already-completed session', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $session = TrainingSession::factory()->for($this->user)
        ->for(Routine::factory()->for($this->user))->completed()->create();
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    $this->actingAs($this->user)->postJson(completeUrl($session), [])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED');

    Bus::assertNotDispatched(SessionAnalysisJob::class);
});

// TC-8
it('reports already-completed before no-sets when both are true', function () {
    $session = TrainingSession::factory()->for($this->user)
        ->for(Routine::factory()->for($this->user))->completed()->create();

    $this->actingAs($this->user)->postJson(completeUrl($session), [])
        ->assertStatus(409)
        ->assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED');
});

// TC-9
it('completes successfully even though the analysis job runs synchronously', function () {
    $session = sessionWithOneSet($this->user);

    $this->actingAs($this->user)->postJson(completeUrl($session), [])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

// TC-10
it('forbids completing another users session', function () {
    $other = User::factory()->create();
    $otherSession = sessionWithOneSet($other);

    $this->actingAs($this->user)->postJson(completeUrl($otherSession), [])
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');

    $this->assertDatabaseHas('training_sessions', ['id' => $otherSession->id, 'status' => 'in_progress']);
});

// TC-11
it('returns 404 for an unknown or non-uuid session', function (string $segment) {
    $this->actingAs($this->user)->postJson("/api/v1/sessions/{$segment}/complete", [])
        ->assertStatus(404);
})->with([
    'unknown uuid' => fn () => (string) Str::uuid(),
    'non-uuid' => '42',
]);

// TC-12
it('rejects an unauthenticated completion', function () {
    $session = sessionWithOneSet(User::factory()->create());

    $this->postJson(completeUrl($session), [])
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');

    $this->assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'in_progress']);
});

// TC-13
it('exposes the full response shape with correct types', function () {
    $session = openPlannedSession($this->user);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    $response = $this->actingAs($this->user)->postJson(completeUrl($session), [
        'note' => 'solid',
        'perceived_effort' => 3,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'status', 'analysis_state', 'note', 'perceived_effort',
            'started_at', 'completed_at', 'created_at', 'updated_at', 'cycle_day',
        ]])
        ->assertJsonPath('data.id', $session->uuid);

    expect($response->json('data.perceived_effort'))->toBeInt()
        ->and($response->json('data.completed_at'))->toMatch(iso8601Pattern());
});
