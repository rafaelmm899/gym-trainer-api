<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

// TC-8
it('returns the callers active routine by uuid', function () {
    $routine = Routine::factory()->for($this->user)->create();

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $routine->uuid)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'goal', 'hint', 'days_per_cycle',
                'status', 'archived_at', 'created_at', 'updated_at',
            ],
        ])
        ->assertJsonMissingPath('data.user_id');
});

// TC-9
it('returns the callers archived routine by uuid', function () {
    $routine = Routine::factory()->for($this->user)->archived()->create();

    $response = $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $routine->uuid)
        ->assertJsonPath('data.status', 'archived');

    expect($response->json('data.archived_at'))->toMatch(iso8601Pattern());
});

// TC-10
it('denies reading another users routine with a 403', function () {
    $other = User::factory()->create();
    $otherRoutine = Routine::factory()->for($other)->create();

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$otherRoutine->uuid}")
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')
        ->assertJsonMissingPath('data.name');
});

// TC-11
it('returns 404 for an unknown uuid', function () {
    $this->actingAs($this->user)->getJson('/api/v1/routines/'.Str::uuid()->toString())
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-12
it('returns 404 for a non-uuid path segment', function () {
    $this->actingAs($this->user)->getJson('/api/v1/routines/not-a-uuid')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-13
it('rejects an unauthenticated request', function () {
    $routine = Routine::factory()->for($this->user)->create();

    $this->getJson("/api/v1/routines/{$routine->uuid}")
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-14b
it('embeds the full cycle tree, ordered, like the create response', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $cycle = Cycle::factory()->for($routine)->create();

    foreach ([2, 1] as $dayOrder) {
        $day = CycleDay::factory()->for($cycle)->create(['order' => $dayOrder]);

        foreach ([2, 1] as $exerciseOrder) {
            DayExercise::factory()->for($day, 'cycleDay')->create(['order' => $exerciseOrder]);
        }
    }

    $response = $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}")
        ->assertOk()
        ->assertJsonPath('data.cycle.id', $cycle->uuid)
        ->assertJsonStructure([
            'data' => [
                'cycle' => [
                    'id', 'sequence_number', 'status', 'split_rationale', 'generated_at',
                    'days' => [
                        '*' => [
                            'id', 'order', 'label', 'focus_muscle_groups', 'rationale',
                            'exercises' => [
                                '*' => [
                                    'id', 'order', 'name', 'sets', 'rep_min', 'rep_max',
                                    'target_weight_kg', 'target_rpe', 'rest_seconds', 'rationale',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

    expect($response->json('data.cycle.days'))->toHaveCount(2)
        ->and(array_column($response->json('data.cycle.days'), 'order'))->toBe([1, 2])
        ->and(array_column($response->json('data.cycle.days.0.exercises'), 'order'))->toBe([1, 2]);
});

// TC-14
it('renders the resource without triggering a lazy load', function () {
    $routine = Routine::factory()->for($this->user)->create();

    $this->actingAs($this->user)->getJson("/api/v1/routines/{$routine->uuid}")
        ->assertOk()
        ->assertJsonMissingPath('data.user');
});
