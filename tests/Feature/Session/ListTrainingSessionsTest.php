<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
});

function seedCompletedSessions(User $user, Routine $routine, int $count = 1, array $attributes = []): void
{
    TrainingSession::factory()->count($count)->for($user)->for($routine)->completed()->create($attributes);
}

function listSessionsUrl(Routine $routine, string $query = ''): string
{
    return "/api/v1/routines/{$routine->uuid}/sessions{$query}";
}

// TC-1
it('lists the routine sessions newest first', function () {
    $old = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create(['started_at' => now()->subDays(3)]);
    $new = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create(['started_at' => now()->subDay()]);
    $mid = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create(['started_at' => now()->subDays(2)]);

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$new->uuid, $mid->uuid, $old->uuid]);
});

// TC-2
it('breaks started_at ties by newest id', function () {
    $startedAt = now()->subDay();
    $first = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create(['started_at' => $startedAt]);
    $second = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create(['started_at' => $startedAt]);

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$second->uuid, $first->uuid]);
});

// TC-3
it('includes planned and free sessions, in progress and completed', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    TrainingSession::factory()->for($this->user)->for($this->routine)->planned($day)->completed()->create(['started_at' => now()->subDays(3)]);
    TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create(['started_at' => now()->subDays(2)]);
    TrainingSession::factory()->for($this->user)->for($this->routine)->create(['started_at' => now()->subDay()]);

    $response = $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect($response->json('data.0.cycle_day'))->toBeNull()
        ->and($response->json('data.1.cycle_day'))->toBeNull()
        ->and($response->json('data.2.cycle_day.id'))->toBe($day->uuid);
});

// TC-4
it('returns only the sessions of the requested routine', function () {
    $other = Routine::factory()->for($this->user)->archived()->create();
    seedCompletedSessions($this->user, $this->routine, 2);
    seedCompletedSessions($this->user, $other, 3);

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// TC-5
it('filters by status completed', function () {
    $completed = TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    TrainingSession::factory()->for($this->user)->for($this->routine)->create();

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine, '?status=completed'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$completed->uuid])
        ->assertJsonPath('meta.total', 1);
});

// TC-6
it('filters by status in_progress', function () {
    TrainingSession::factory()->for($this->user)->for($this->routine)->completed()->create();
    $open = TrainingSession::factory()->for($this->user)->for($this->routine)->create();

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine, '?status=in_progress'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$open->uuid]);
});

// TC-7
it('rejects an invalid status', function () {
    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine, '?status=archived'))
        ->assertUnprocessable()
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION')
        ->assertJsonValidationErrors(['status'], 'data.errors');
});

// TC-8
it('paginates with the default page size', function () {
    seedCompletedSessions($this->user, $this->routine, 20);

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('links.next', fn ($next) => $next !== null);
});

// TC-9
it('honours page and per_page', function () {
    foreach (range(1, 20) as $i) {
        TrainingSession::factory()->for($this->user)->for($this->routine)->completed()
            ->create(['started_at' => now()->subMinutes($i)]);
    }
    $expected = TrainingSession::query()->where('routine_id', $this->routine->id)
        ->orderByDesc('started_at')->orderByDesc('id')->skip(5)->take(5)->pluck('uuid')->all();

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine, '?per_page=5&page=2'))
        ->assertOk()
        ->assertJsonPath('data.*.id', $expected)
        ->assertJsonPath('meta.current_page', 2);
});

// TC-10
it('rejects out-of-range pagination input', function (string $query, string $field) {
    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine, $query))
        ->assertUnprocessable()
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION')
        ->assertJsonValidationErrors([$field], 'data.errors');
})->with([
    'per_page zero' => ['?per_page=0', 'per_page'],
    'per_page too big' => ['?per_page=51', 'per_page'],
    'per_page text' => ['?per_page=abc', 'per_page'],
    'page zero' => ['?page=0', 'page'],
]);

// TC-11
it('returns an empty page when there are no sessions', function () {
    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

// TC-12
it('works for an archived routine', function () {
    $archived = Routine::factory()->for($this->user)->archived()->create();
    seedCompletedSessions($this->user, $archived);

    $this->actingAs($this->user)->getJson(listSessionsUrl($archived))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// TC-13
it('shapes each item with the session resource fields', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    TrainingSession::factory()->for($this->user)->for($this->routine)->planned($day)->completed()->create();

    $response = $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonStructure(['data' => ['*' => [
            'id', 'status', 'analysis_state', 'note', 'perceived_effort',
            'started_at', 'completed_at', 'created_at', 'updated_at', 'cycle_day' => ['id', 'order', 'label'],
        ]]])
        ->assertJsonMissingPath('data.0.user_id')
        ->assertJsonMissingPath('data.0.routine_id')
        ->assertJsonMissingPath('data.0.cycle_day.exercises');

    $item = $response->json('data.0');
    expect(Str::isUuid($item['id']))->toBeTrue()
        ->and($item['status'])->toBe('completed')
        ->and($item['analysis_state'])->toBeString()
        ->and(CarbonImmutable::parse($item['started_at'])->toIso8601String())->toBe($item['started_at']);
});

// TC-14
it('does not lazy load the cycle day', function () {
    $day = CycleDay::factory()->for(Cycle::factory()->for($this->routine))->create();
    seedCompletedSessions($this->user, $this->routine, 3, ['cycle_day_id' => $day->id]);

    $this->actingAs($this->user)->getJson(listSessionsUrl($this->routine))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

// TC-15
it('requires authentication', function () {
    $this->getJson(listSessionsUrl($this->routine))
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-16
it('forbids another users routine', function () {
    $owner = User::factory()->create();
    $foreign = Routine::factory()->for($owner)->create();
    seedCompletedSessions($owner, $foreign);

    $this->actingAs($this->user)->getJson(listSessionsUrl($foreign))
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')
        ->assertJsonMissingPath('data.0');
});

// TC-17
it('returns 404 for an unknown or malformed routine id', function (string $id) {
    $this->actingAs($this->user)->getJson("/api/v1/routines/{$id}/sessions")
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
})->with([
    'unknown uuid' => fn () => (string) Str::uuid(),
    'not a uuid' => 'not-a-uuid',
]);
