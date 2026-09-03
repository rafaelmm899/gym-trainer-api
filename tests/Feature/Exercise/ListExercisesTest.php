<?php

use App\Enums\Shared\MuscleGroup;
use App\Models\Exercise;
use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

// TC-1
it('lists the catalogue ordered by name', function () {
    Exercise::factory()->create(['name' => 'Zercher Squat', 'slug' => 'zercher-squat']);
    Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'bench-press']);
    Exercise::factory()->create(['name' => 'Deadlift', 'slug' => 'deadlift']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/exercises');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Bench Press')
        ->assertJsonPath('data.2.name', 'Zercher Squat')
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'primary_muscle_group']]]);

    expect($response->json('data.0.id'))->toMatch(uuidV4Pattern());
});

// TC-2
it('filters by a case-insensitive substring of the name', function () {
    Exercise::factory()->create(['name' => 'Barbell Bench Press', 'slug' => 'barbell-bench-press']);
    Exercise::factory()->create(['name' => 'Incline Bench Press', 'slug' => 'incline-bench-press']);
    Exercise::factory()->create(['name' => 'Deadlift', 'slug' => 'deadlift']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/exercises?q=bench');

    $response->assertOk()->assertJsonCount(2, 'data');
    expect(collect($response->json('data'))->pluck('name'))
        ->each->toContain('Bench');
});

// TC-3
it('filters by muscle_group and ignores an unrecognised value', function () {
    Exercise::factory()->create(['name' => 'A', 'slug' => 'a', 'primary_muscle_group' => MuscleGroup::Chest]);
    Exercise::factory()->create(['name' => 'B', 'slug' => 'b', 'primary_muscle_group' => MuscleGroup::Back]);
    Exercise::factory()->create(['name' => 'C', 'slug' => 'c', 'primary_muscle_group' => null]);

    $this->actingAs($this->user)->getJson('/api/v1/exercises?muscle_group=chest')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.primary_muscle_group', 'chest');

    $this->actingAs($this->user)->getJson('/api/v1/exercises?muscle_group=not-a-group')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

// TC-4
it('caps the result set at 50 rows', function () {
    Exercise::factory()->count(60)->create();

    $this->actingAs($this->user)->getJson('/api/v1/exercises')
        ->assertOk()
        ->assertJsonCount(50, 'data');
});

// TC-5
it('returns an empty data array when nothing matches', function () {
    Exercise::factory()->create(['name' => 'Deadlift', 'slug' => 'deadlift']);

    $this->actingAs($this->user)->getJson('/api/v1/exercises?q=nothingmatches')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

// TC-6
it('exposes the uuid as id and never the internal id or created_by_ai', function () {
    $exercise = Exercise::factory()->create(['name' => 'Deadlift', 'slug' => 'deadlift']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/exercises');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $exercise->uuid)
        ->assertJsonMissingPath('data.0.created_by_ai')
        ->assertJsonMissingPath('data.0.created_at');
});

// TC-7
it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/exercises')
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});
