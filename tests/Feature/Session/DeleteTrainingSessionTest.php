<?php

use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function deleteSessionUrl(TrainingSession $session): string
{
    return "/api/v1/sessions/{$session->uuid}";
}

// TC-1
it('deletes an in_progress free session', function () {
    $session = openFreeSession($this->user);

    $response = $this->actingAs($this->user)->deleteJson(deleteSessionUrl($session));

    $response->assertNoContent();
    $this->assertDatabaseMissing('training_sessions', ['id' => $session->id]);
});

// TC-2
it('deletes an in_progress planned session without touching its cycle_day', function () {
    $session = openPlannedSession($this->user);
    $cycleDayId = $session->cycle_day_id;

    $response = $this->actingAs($this->user)->deleteJson(deleteSessionUrl($session));

    $response->assertNoContent();
    $this->assertDatabaseMissing('training_sessions', ['id' => $session->id]);
    $this->assertDatabaseHas('cycle_days', ['id' => $cycleDayId]);
});

// TC-3
it('cascades the deletion to set_logs but never to exercises', function () {
    $session = openFreeSession($this->user);
    $exercise = Exercise::factory()->create();
    $set = SetLog::factory()->for($session, 'session')->for($exercise)->create();

    $response = $this->actingAs($this->user)->deleteJson(deleteSessionUrl($session));

    $response->assertNoContent();
    $this->assertDatabaseMissing('set_logs', ['id' => $set->id]);
    $this->assertDatabaseHas('exercises', ['id' => $exercise->id]);
});

// TC-4
it('lets the user open a new session after deleting the one blocking them', function () {
    $session = openFreeSession($this->user);
    $routine = $session->routine;

    $this->actingAs($this->user)->deleteJson(deleteSessionUrl($session))->assertNoContent();

    $response = $this->actingAs($this->user)->postJson("/api/v1/routines/{$routine->uuid}/sessions", []);

    $response->assertCreated();
});

// TC-5
it('refuses to delete an already completed session', function () {
    $session = TrainingSession::factory()->for($this->user)->for(Routine::factory()->for($this->user))->completed()->create();
    $set = SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create();

    $response = $this->actingAs($this->user)->deleteJson(deleteSessionUrl($session));

    $response->assertStatus(409)->assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED');
    $this->assertDatabaseHas('training_sessions', ['id' => $session->id]);
    $this->assertDatabaseHas('set_logs', ['id' => $set->id]);
});

// TC-6
it('denies deleting another users session with a 403', function () {
    $other = User::factory()->create();
    $otherSession = openFreeSession($other);

    $response = $this->actingAs($this->user)->deleteJson(deleteSessionUrl($otherSession));

    $response->assertForbidden()->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');
    $this->assertDatabaseHas('training_sessions', ['id' => $otherSession->id]);
});

// TC-7
it('returns 404 for an unknown or non-uuid session', function (string $segment, ?string $expectedCode) {
    $response = $this->actingAs($this->user)->deleteJson("/api/v1/sessions/{$segment}");

    $response->assertNotFound();

    if ($expectedCode !== null) {
        $response->assertJsonPath('data.code', $expectedCode);
    }
})->with([
    'unknown uuid' => fn () => [(string) Str::uuid(), 'NOT_FOUND_EXCEPTION'],
    'non-uuid segment' => fn () => ['42', null],
]);

// TC-8
it('rejects an unauthenticated request', function () {
    $session = openFreeSession(User::factory()->create());

    $response = $this->deleteJson(deleteSessionUrl($session));

    $response->assertUnauthorized()->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
    $this->assertDatabaseHas('training_sessions', ['id' => $session->id]);
});
