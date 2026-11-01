<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\User;
use Illuminate\Support\Str;

// docs/plans/session-redesign-data-spec.md §8, TC-12..TC-23 and TC-48.

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
    $this->exercise = Exercise::factory()->create();
});

function historyUrl(Exercise|string $exercise, string $query = ''): string
{
    $id = $exercise instanceof Exercise ? $exercise->uuid : $exercise;

    return "/api/v1/exercises/{$id}/history".($query === '' ? '' : "?{$query}");
}

// TC-12
it('requires authentication', function () {
    $this->getJson(historyUrl($this->exercise))
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-13
it('returns 404 for an unknown exercise', function () {
    $this->actingAs($this->user)->getJson(historyUrl((string) Str::uuid()))
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-14
it('returns an empty list for an exercise the user never trained', function () {
    $this->actingAs($this->user)->getJson(historyUrl($this->exercise))
        ->assertOk()
        ->assertJsonPath('data', []);
});

// TC-15
it('lists completed sessions of the user newest first and nothing else', function () {
    $oldest = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(3));
    $middle = completedSessionWith($this->user, $this->routine, $this->exercise, [[55, 10]], now()->subDays(2));
    $newest = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10]], now()->subDay());

    $open = openFreeSession($this->user);
    SetLog::factory()->for($open, 'session')->for($this->exercise)->create(['set_number' => 1]);

    $stranger = User::factory()->create();
    completedSessionWith($stranger, Routine::factory()->for($stranger)->create(), $this->exercise, [[200, 10]], now()->subHours(2));

    $this->actingAs($this->user)->getJson(historyUrl($this->exercise))
        ->assertOk()
        ->assertJsonPath('data.*.session_id', [$newest->uuid, $middle->uuid, $oldest->uuid]);
});

// TC-16
it('shapes each item with top weight, volume, sets and cycle data', function () {
    $cycle = Cycle::factory()->for($this->routine)->create(['sequence_number' => 2]);
    $day = CycleDay::factory()->for($cycle)->create(['label' => 'Pecho y tríceps']);
    $session = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 8], [62.5, 6], [62.5, 5]], now()->subDay(), $day);
    SetLog::query()->where('session_id', $session->id)->where('set_number', 2)->update(['note' => 'pesado', 'rpe' => 8.5]);
    SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create(['set_number' => 1, 'weight_kg' => 999, 'reps' => 9]);

    $item = $this->actingAs($this->user)->getJson(historyUrl($this->exercise))->assertOk()->json('data.0');

    expect($item['session_id'])->toBe($session->uuid)
        ->and($item['started_at'])->toBe($session->started_at->toIso8601String())
        ->and($item['cycle_sequence_number'])->toBe(2)
        ->and($item['cycle_day_label'])->toBe('Pecho y tríceps')
        ->and($item['top_weight_kg'])->toEqual(62.5)
        ->and($item['volume_kg'])->toEqual(1167.5)
        ->and($item['volume_change_pct'])->toBeNull()
        ->and($item['sets'])->toHaveCount(3)
        ->and($item['sets'][1])->toEqual(['set_number' => 2, 'weight_kg' => 62.5, 'reps' => 6, 'rpe' => 8.5, 'note' => 'pesado'])
        ->and($item['sets'][0]['note'])->toBeNull();
});

// TC-17
it('reports no cycle data for a free session', function () {
    completedSessionWith($this->user, $this->routine, $this->exercise, [[40, 10]], now()->subDay());

    $this->actingAs($this->user)->getJson(historyUrl($this->exercise))
        ->assertOk()
        ->assertJsonPath('data.0.cycle_sequence_number', null)
        ->assertJsonPath('data.0.cycle_day_label', null);
});

// TC-18
it('compares each volume with the previous session across routines and free sessions', function () {
    $other = Routine::factory()->for($this->user)->archived()->create();
    completedSessionWith($this->user, $other, $this->exercise, [[100, 10]], now()->subDays(3));
    completedSessionWith($this->user, $this->routine, $this->exercise, [[110, 10]], now()->subDays(2));
    completedSessionWith($this->user, $other, $this->exercise, [[99, 10]], now()->subDay());

    $pct = $this->actingAs($this->user)->getJson(historyUrl($this->exercise))->assertOk()->json('data.*.volume_change_pct');

    expect($pct)->toEqual([-10.0, 10.0, null]);
});

// TC-19
it('returns a null percentage after a zero-volume session and rounds to one decimal', function () {
    completedSessionWith($this->user, $this->routine, $this->exercise, [[0, 10]], now()->subDays(3));
    completedSessionWith($this->user, $this->routine, $this->exercise, [[30, 10]], now()->subDays(2));
    completedSessionWith($this->user, $this->routine, $this->exercise, [[31, 10]], now()->subDay());

    $pct = $this->actingAs($this->user)->getJson(historyUrl($this->exercise))->assertOk()->json('data.*.volume_change_pct');

    expect($pct)->toEqual([3.3, null, null]);
});

// TC-20
it('computes the percentage correctly across the page boundary', function () {
    foreach ([100, 110, 99, 120, 150] as $index => $weight) {
        completedSessionWith($this->user, $this->routine, $this->exercise, [[$weight, 10]], now()->subDays(5 - $index));
    }

    $this->actingAs($this->user);
    $second = $this->getJson(historyUrl($this->exercise, 'per_page=2&page=2'))->assertOk();
    $third = $this->getJson(historyUrl($this->exercise, 'per_page=2&page=3'))->assertOk();

    expect($second->json('data.*.volume_change_pct'))->toEqual([-10.0, 10.0])
        ->and($third->json('data.*.volume_change_pct'))->toEqual([null])
        ->and($second->json('meta.total'))->toBe(5)
        ->and($second->json('meta.current_page'))->toBe(2);
});

// TC-21
it('limits the list with the routine filter but keeps the comparison base', function () {
    $other = Routine::factory()->for($this->user)->archived()->create();
    foreach ([[$this->routine, 100], [$other, 110], [$this->routine, 99], [$other, 120], [$this->routine, 150]] as $index => [$routine, $weight]) {
        completedSessionWith($this->user, $routine, $this->exercise, [[$weight, 10]], now()->subDays(5 - $index));
    }

    $this->actingAs($this->user);
    $all = collect($this->getJson(historyUrl($this->exercise))->assertOk()->json('data'))->keyBy('session_id');
    $filtered = $this->getJson(historyUrl($this->exercise, "routine={$this->routine->uuid}"))->assertOk()->json('data');

    expect($filtered)->toHaveCount(3);

    foreach ($filtered as $item) {
        expect($item['volume_change_pct'])->toEqual($all[$item['session_id']]['volume_change_pct']);
    }
});

// TC-22
it('returns an empty list for a foreign or unknown routine and 422 for a malformed one', function () {
    completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDay());
    $stranger = Routine::factory()->for(User::factory())->create();

    $this->actingAs($this->user);
    $this->getJson(historyUrl($this->exercise, "routine={$stranger->uuid}"))->assertOk()->assertJsonPath('data', []);
    $this->getJson(historyUrl($this->exercise, 'routine='.Str::uuid()))->assertOk()->assertJsonPath('data', []);
    $this->getJson(historyUrl($this->exercise, 'routine=not-a-uuid'))
        ->assertUnprocessable()
        ->assertJsonPath('data.code', 'VALIDATION_EXCEPTION')
        ->assertJsonValidationErrors(['routine'], 'data.errors');
});

// TC-23
it('validates the pagination parameters and defaults per_page to 10', function () {
    foreach (range(1, 12) as $daysAgo) {
        completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays($daysAgo));
    }

    $this->actingAs($this->user);

    foreach (['per_page=0', 'per_page=51', 'per_page=abc'] as $query) {
        $this->getJson(historyUrl($this->exercise, $query))->assertUnprocessable()->assertJsonValidationErrors(['per_page'], 'data.errors');
    }
    $this->getJson(historyUrl($this->exercise, 'page=0'))->assertUnprocessable()->assertJsonValidationErrors(['page'], 'data.errors');

    $this->getJson(historyUrl($this->exercise))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 12);
});

// TC-48
it('orders sessions with the same started_at by id descending', function () {
    $startedAt = now()->subDay();
    $first = completedSessionWith($this->user, $this->routine, $this->exercise, [[100, 10]], $startedAt);
    $second = completedSessionWith($this->user, $this->routine, $this->exercise, [[110, 10]], $startedAt);

    $response = $this->actingAs($this->user)->getJson(historyUrl($this->exercise))->assertOk();

    expect($response->json('data.*.session_id'))->toBe([$second->uuid, $first->uuid])
        ->and($response->json('data.*.volume_change_pct'))->toEqual([10.0, null]);
});
