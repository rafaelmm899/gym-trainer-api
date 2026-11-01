<?php

namespace App\Services\Exercise;

use App\Data\Exercise\ExercisePerformanceData;
use App\Enums\Session\SessionStatus;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a user did on an exercise across their completed sessions. One code
 * path answers the three questions the session screens ask: the last time
 * (`previousFor`), the dated list (`pageFor`) and the recent trend
 * (`trendFor`) — so "volume" and "top set" mean the same thing everywhere.
 *
 * Every lookup starts from the lightweight (exercise, session) pairs of the
 * user's completed sessions, newest first by `(started_at, id)`, and only
 * loads sets for the sessions it actually returns.
 */
final class ExerciseHistoryService
{
    private const TREND_POINTS = 4;

    /**
     * The most recent completed session strictly before `$before` that has a
     * set of each exercise, keyed by `exercise_id`. Exercises never trained
     * before are absent.
     *
     * @param  list<int>  $exerciseIds
     * @return Collection<int, ExercisePerformanceData>
     */
    public function previousFor(User $user, CarbonInterface $before, array $exerciseIds): Collection
    {
        $latest = $this->pairs($user, $exerciseIds)
            ->filter(fn (stdClass $pair): bool => CarbonImmutable::parse($pair->started_at)->lt($before))
            ->groupBy('exercise_id')
            ->map(fn (Collection $pairs): object => $pairs->first());

        return $this->performances($latest, $exerciseIds)->mapWithKeys(
            fn (ExercisePerformanceData $data, string $key): array => [(int) explode(':', $key)[0] => $data],
        );
    }

    /**
     * Sets `previous` (an `ExercisePerformanceData` or `null`) as a relation on
     * every prescribed exercise of the session's planned day. The day's
     * `dayExercises` must already be loaded; a free session has none.
     */
    public function attachPrevious(User $user, TrainingSession $session): void
    {
        if ($session->cycleDay === null) {
            return;
        }

        $dayExercises = $session->cycleDay->dayExercises;

        $previous = $this->previousFor($user, $session->started_at, $dayExercises->pluck('exercise_id')->unique()->all());

        foreach ($dayExercises as $dayExercise) {
            $dayExercise->setRelation('previous', $previous->get($dayExercise->exercise_id));
        }
    }

    /**
     * One page of the user's completed sessions of `$exercise`, newest first.
     * `volume_change_pct` compares each item with the session immediately
     * before it for the exercise — across routines and free sessions, and
     * regardless of the `routine` filter or the page window.
     *
     * @return LengthAwarePaginator<int, ExercisePerformanceData>
     */
    public function pageFor(User $user, Exercise $exercise, ?string $routineUuid, int $perPage): LengthAwarePaginator
    {
        $all = $this->pairs($user, [$exercise->id])->values();

        $listed = $all;

        if ($routineUuid !== null) {
            $routineId = Routine::query()->where('user_id', $user->id)->where('uuid', $routineUuid)->value('id');
            $listed = $all->where('routine_id', $routineId)->values();
        }

        $page = Paginator::resolveCurrentPage();
        $items = $listed->forPage($page, $perPage)->values();

        $positionOf = $all->pluck('session_id')->flip();
        $previousIdOf = fn (stdClass $pair): ?int => $all->get($positionOf[$pair->session_id] + 1)?->session_id;

        $performances = $this->performances($items, [$exercise->id]);
        $previousVolumes = $this->volumes($items->map($previousIdOf)->filter()->unique()->all(), $exercise->id);

        $data = $items->map(function (stdClass $pair) use ($performances, $previousIdOf, $previousVolumes, $exercise): ExercisePerformanceData {
            $current = $performances->get($exercise->id.':'.$pair->session_id);
            $previousVolume = $previousVolumes->get($previousIdOf($pair));

            return $current->withVolumeChangePct($this->changePct($current->volumeKg, $previousVolume));
        });

        return new LengthAwarePaginator($data, $listed->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Up to the last four completed sessions per recommendation, oldest
     * first, ending at the recommendation's source session (included). A
     * recommendation without a source session takes the four most recent.
     * Keyed by recommendation id.
     *
     * @param  EloquentCollection<int, ExerciseRecommendation>  $recommendations
     * @return Collection<int, array<int, ExercisePerformanceData>>
     */
    public function trendFor(User $user, EloquentCollection $recommendations): Collection
    {
        $recommendations->loadMissing('sourceSession');

        $pairs = $this->pairs($user, $recommendations->pluck('exercise_id')->unique()->all());

        $chosen = $recommendations->mapWithKeys(function (ExerciseRecommendation $recommendation) use ($pairs): array {
            $source = $recommendation->sourceSession;

            $points = $pairs
                ->where('exercise_id', $recommendation->exercise_id)
                ->filter(fn (stdClass $pair): bool => $source === null || $this->notAfter($pair, $source))
                ->take(self::TREND_POINTS)
                ->reverse()
                ->values();

            return [$recommendation->id => $points];
        });

        $performances = $this->performances($chosen->flatten(1), $recommendations->pluck('exercise_id')->unique()->all());

        return $chosen->map(fn (Collection $points): array => $points
            ->map(fn (stdClass $pair): ExercisePerformanceData => $performances->get($pair->exercise_id.':'.$pair->session_id))
            ->all());
    }

    /**
     * Completed sessions of the user that logged at least one set of the given
     * exercises, one row per (exercise, session), newest first.
     *
     * @param  list<int>  $exerciseIds
     * @return Collection<int, stdClass>
     */
    private function pairs(User $user, array $exerciseIds): Collection
    {
        return DB::table('set_logs')
            ->join('training_sessions as t', 't.id', '=', 'set_logs.session_id')
            ->where('t.user_id', $user->id)
            ->where('t.status', SessionStatus::Completed->value)
            ->whereIn('set_logs.exercise_id', $exerciseIds)
            ->groupBy('set_logs.exercise_id', 't.id', 't.started_at', 't.routine_id')
            ->orderByDesc('t.started_at')
            ->orderByDesc('t.id')
            ->get(['set_logs.exercise_id', 't.id as session_id', 't.started_at', 't.routine_id']);
    }

    /**
     * Builds the performance of each pair. Keyed by `"{exercise_id}:{session_id}"`.
     *
     * @param  Collection<array-key, stdClass>  $pairs
     * @param  list<int>  $exerciseIds
     * @return Collection<string, ExercisePerformanceData>
     */
    private function performances(Collection $pairs, array $exerciseIds): Collection
    {
        if ($pairs->isEmpty()) {
            return collect();
        }

        $sessions = TrainingSession::query()
            ->whereIn('id', $pairs->pluck('session_id')->unique()->all())
            ->with([
                'cycleDay.cycle',
                'sets' => fn ($sets) => $sets->whereIn('exercise_id', $exerciseIds)->orderBy('set_number'),
            ])
            ->get()
            ->keyBy('id');

        return $pairs->mapWithKeys(function (stdClass $pair) use ($sessions): array {
            $session = $sessions->get($pair->session_id);
            $sets = $session->sets->where('exercise_id', $pair->exercise_id)->values();

            return [$pair->exercise_id.':'.$pair->session_id => $this->performance($session, $sets)];
        });
    }

    /**
     * @param  Collection<int, SetLog>  $sets
     */
    private function performance(TrainingSession $session, Collection $sets): ExercisePerformanceData
    {
        $top = $sets
            ->sort(fn (SetLog $a, SetLog $b): int => [(float) $b->weight_kg, $b->reps, $a->set_number]
                <=> [(float) $a->weight_kg, $a->reps, $b->set_number])
            ->first();

        return new ExercisePerformanceData(
            sessionId: $session->uuid,
            startedAt: $session->started_at,
            cycleSequenceNumber: $session->cycleDay?->cycle->sequence_number,
            cycleDayLabel: $session->cycleDay?->label,
            topWeightKg: (float) $top->weight_kg,
            topSetReps: $top->reps,
            volumeKg: round($sets->sum(fn (SetLog $set): float => (float) $set->weight_kg * $set->reps), 2),
            sets: $sets->map(fn (SetLog $set): array => [
                'set_number' => $set->set_number,
                'weight_kg' => (float) $set->weight_kg,
                'reps' => $set->reps,
                'rpe' => $set->rpe !== null ? (float) $set->rpe : null,
                'note' => $set->note,
            ])->all(),
        );
    }

    /**
     * Volume of the exercise in each session, keyed by session id.
     *
     * @param  list<int>  $sessionIds
     * @return Collection<int, float>
     */
    private function volumes(array $sessionIds, int $exerciseId): Collection
    {
        if ($sessionIds === []) {
            return collect();
        }

        return SetLog::query()
            ->whereIn('session_id', $sessionIds)
            ->where('exercise_id', $exerciseId)
            ->get(['session_id', 'weight_kg', 'reps'])
            ->groupBy('session_id')
            ->map(fn (Collection $sets): float => round($sets->sum(fn (SetLog $set): float => (float) $set->weight_kg * $set->reps), 2));
    }

    private function changePct(float $volume, ?float $previousVolume): ?float
    {
        if ($previousVolume === null || $previousVolume == 0.0) {
            return null;
        }

        return round(($volume - $previousVolume) / $previousVolume * 100, 1);
    }

    private function notAfter(object $pair, TrainingSession $source): bool
    {
        $startedAt = CarbonImmutable::parse($pair->started_at);

        return $startedAt->lt($source->started_at)
            || ($startedAt->eq($source->started_at) && $pair->session_id <= $source->id);
    }
}
