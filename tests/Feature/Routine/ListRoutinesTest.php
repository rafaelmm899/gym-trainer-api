<?php

use App\Enums\Shared\Goal;
use App\Models\Routine;
use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

// TC-1
it('lists the callers routines, active first then archived newest-first', function () {
    $olderArchived = null;
    $newerArchived = null;

    $this->travelTo(now()->subDays(2), function () use (&$olderArchived) {
        $olderArchived = Routine::factory()->for($this->user)->archived()->create();
    });

    $this->travelTo(now()->subDay(), function () use (&$newerArchived) {
        $newerArchived = Routine::factory()->for($this->user)->archived()->create();
    });

    $active = Routine::factory()->for($this->user)->create();

    $this->actingAs($this->user)->getJson('/api/v1/routines')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $active->uuid)
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.1.id', $newerArchived->uuid)
        ->assertJsonPath('data.1.status', 'archived')
        ->assertJsonPath('data.2.id', $olderArchived->uuid);
});

// TC-2
it('returns only the callers routines', function () {
    $mine = Routine::factory()->for($this->user)->create();

    $other = User::factory()->create();
    $otherActive = Routine::factory()->for($other)->create();
    $otherArchived = Routine::factory()->for($other)->archived()->create();

    $response = $this->actingAs($this->user)->getJson('/api/v1/routines')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($mine->uuid)
        ->and($ids)->not->toContain($otherActive->uuid)
        ->and($ids)->not->toContain($otherArchived->uuid);
});

// TC-3
it('returns an empty list for a user with no routines', function () {
    $this->actingAs($this->user)->getJson('/api/v1/routines')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

// TC-4
it('shapes each item as the full routine resource without the internal id', function () {
    $routine = Routine::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)->getJson('/api/v1/routines')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'name', 'goal', 'hint', 'days_per_cycle',
                    'status', 'archived_at', 'created_at', 'updated_at',
                ],
            ],
        ])
        ->assertJsonMissingPath('data.0.user_id');

    expect($response->json('data.0.id'))->toBe($routine->uuid)
        ->and($response->json('data.0.id'))->toMatch(uuidV4Pattern())
        ->and($response->json('data.0.id'))->not->toBe((string) $routine->id);
});

// TC-5
it('serialises enums as strings and dates as ISO-8601', function () {
    Routine::factory()->for($this->user)->create(['goal' => Goal::Strength]);

    $response = $this->actingAs($this->user)->getJson('/api/v1/routines')->assertOk();

    expect($response->json('data.0.status'))->toBe('active')
        ->and($response->json('data.0.goal'))->toBe('strength')
        ->and($response->json('data.0.days_per_cycle'))->toBe(5)
        ->and($response->json('data.0.created_at'))->toMatch(iso8601Pattern())
        ->and($response->json('data.0.archived_at'))->toBeNull();
});

// TC-6
it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/routines')
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-7
it('renders the collection without triggering a lazy load', function () {
    Routine::factory()->for($this->user)->create();
    Routine::factory()->for($this->user)->archived()->create();

    $this->actingAs($this->user)->getJson('/api/v1/routines')
        ->assertOk()
        ->assertJsonMissingPath('data.0.user')
        ->assertJsonMissingPath('data.1.user');
});
