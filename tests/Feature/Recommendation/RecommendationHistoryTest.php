<?php

use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;

// docs/plans/session-redesign-data-spec.md §8, TC-30..TC-34 and TC-47.

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
    $this->routine = Routine::factory()->for($this->user)->create();
    $this->cycle = Cycle::factory()->for($this->routine)->create();
    $this->exercise = Exercise::factory()->create();
});

function recommendationsUrl(Routine $routine): string
{
    return "/api/v1/routines/{$routine->uuid}/recommendations";
}

/**
 * A recommendation for `$exercise` whose exercise is prescribed in the routine's
 * current cycle, so the routine recommendations list returns it.
 */
function currentRecommendation(Routine $routine, Cycle $cycle, Exercise $exercise, ?TrainingSession $source): ExerciseRecommendation
{
    $day = CycleDay::factory()->for($cycle)->create(['order' => $cycle->cycleDays()->count() + 1]);
    $day->dayExercises()->create([
        'exercise_id' => $exercise->id,
        'order' => 1,
        'sets' => 3,
        'rep_min' => 8,
        'rep_max' => 12,
        'rest_seconds' => 90,
        'rationale' => 'test',
    ]);

    return ExerciseRecommendation::factory()->for($routine->user)->for($routine)
        ->create(['exercise_id' => $exercise->id, 'source_session_id' => $source?->id]);
}

// TC-30
it('ends the history at the source session, oldest to newest', function () {
    $sessions = collect(range(5, 1))->map(fn (int $daysAgo) => completedSessionWith(
        $this->user, $this->routine, $this->exercise, [[100 - $daysAgo, 5]], now()->subDays($daysAgo),
    ))->values();
    currentRecommendation($this->routine, $this->cycle, $this->exercise, $sessions[3]);

    $this->actingAs($this->user);
    $fromList = $this->getJson(recommendationsUrl($this->routine))->assertOk()->json('data.0.history');

    $fromDetail = $this->getJson("/api/v1/sessions/{$sessions[3]->uuid}")->assertOk()->json('data.recommendations');

    expect(array_column($fromList, 'session_id'))->toBe($sessions->take(4)->map->uuid->all())
        ->and($fromList[0])->toHaveKeys(['session_id', 'started_at', 'top_weight_kg', 'top_set_reps', 'volume_kg'])
        ->and($fromDetail)->toHaveCount(1)
        ->and(array_column($fromDetail[0]['history'], 'session_id'))->toBe($sessions->take(4)->map->uuid->all());
});

// TC-31
it('reports the top set with the tie resolved by reps', function () {
    $source = completedSessionWith($this->user, $this->routine, $this->exercise, [[57.5, 8], [60, 5], [60, 7], [60, 7]], now()->subDay());
    currentRecommendation($this->routine, $this->cycle, $this->exercise, $source);

    $point = $this->actingAs($this->user)->getJson(recommendationsUrl($this->routine))->assertOk()->json('data.0.history.0');

    expect($point['top_weight_kg'])->toEqual(60.0)
        ->and($point['top_set_reps'])->toBe(7)
        ->and($point['volume_kg'])->toEqual(460 + 300 + 420 + 420.0)
        ->and($point['started_at'])->toBe($source->started_at->toIso8601String());
});

// TC-32
it('spans the user routines and never another user', function () {
    $other = Routine::factory()->for($this->user)->archived()->create();
    $elsewhere = completedSessionWith($this->user, $other, $this->exercise, [[50, 10]], now()->subDays(5));
    $stranger = User::factory()->create();
    completedSessionWith($stranger, Routine::factory()->for($stranger)->create(), $this->exercise, [[300, 10]], now()->subDays(4));
    $source = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10]], now()->subDay());
    currentRecommendation($this->routine, $this->cycle, $this->exercise, $source);

    $history = $this->actingAs($this->user)->getJson(recommendationsUrl($this->routine))->assertOk()->json('data.0.history');

    expect(array_column($history, 'session_id'))->toBe([$elsewhere->uuid, $source->uuid]);
});

// TC-33
it('returns fewer points when there are fewer sessions and the latest four without a source', function () {
    $short = completedSessionWith($this->user, $this->routine, $this->exercise, [[50, 10]], now()->subDays(9));
    $source = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10]], now()->subDays(8));
    currentRecommendation($this->routine, $this->cycle, $this->exercise, $source);

    $unsourcedExercise = Exercise::factory()->create();
    $recent = collect(range(6, 1))->map(fn (int $daysAgo) => completedSessionWith(
        $this->user, $this->routine, $unsourcedExercise, [[40, 10]], now()->subDays($daysAgo),
    ))->values();
    currentRecommendation($this->routine, $this->cycle, $unsourcedExercise, null);

    $data = collect($this->actingAs($this->user)->getJson(recommendationsUrl($this->routine))->assertOk()->json('data'));
    $byExercise = fn (Exercise $exercise) => $data->firstWhere('exercise.id', $exercise->uuid)['history'];

    expect(array_column($byExercise($this->exercise), 'session_id'))->toBe([$short->uuid, $source->uuid])
        ->and(array_column($byExercise($unsourcedExercise), 'session_id'))->toBe($recent->slice(2)->map->uuid->values()->all());
});

// TC-34
it('keeps the recommendations list query count flat as recommendations grow', function () {
    $queriesWith = function (int $count): int {
        $user = User::factory()->create();
        $routine = Routine::factory()->for($user)->create();
        $cycle = Cycle::factory()->for($routine)->create();

        foreach (range(1, $count) as $unused) {
            $exercise = Exercise::factory()->create();
            $source = completedSessionWith($user, $routine, $exercise, [[50, 10]], now()->subDays(3));
            currentRecommendation($routine, $cycle, $exercise, $source);
        }

        $this->actingAs($user);

        return countQueries(fn () => $this->getJson(recommendationsUrl($routine))->assertOk()->assertJsonCount($count, 'data'));
    };

    expect($queriesWith(6))->toBe($queriesWith(2));
});

// TC-47
it('ignores sessions that are not completed', function () {
    $open = TrainingSession::factory()->for($this->user)->for($this->routine)->create(['started_at' => now()->subDays(3)]);
    SetLog::factory()->for($open, 'session')->for($this->exercise)->create(['set_number' => 1]);
    $source = completedSessionWith($this->user, $this->routine, $this->exercise, [[60, 10]], now()->subDay());
    currentRecommendation($this->routine, $this->cycle, $this->exercise, $source);

    $history = $this->actingAs($this->user)->getJson(recommendationsUrl($this->routine))->assertOk()->json('data.0.history');

    expect(array_column($history, 'session_id'))->toBe([$source->uuid]);
});
