<?php

namespace App\Services\Session;

use App\Data\Session\SessionSummaryData;
use App\Enums\Session\SessionStatus;
use App\Models\SetLog;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Counts, volume, duration and volume change of whole pages of sessions, in a
 * fixed number of queries (no early exits) however many sessions there are.
 *
 * `volume_change_pct` compares a completed planned session with the most
 * recent completed session of the same routine on the same plan day (same
 * `order`) in the immediately previous cycle. It is `null` for in-progress and
 * free sessions, for cycle 1, when that day was not completed in the previous
 * cycle, and when the baseline volume is 0.
 */
final class SessionSummaryService
{
    /**
     * Sets a `summary` on each session (a relation, so it is never persisted).
     *
     * @param  EloquentCollection<int, TrainingSession>  $sessions
     */
    public function attach(EloquentCollection $sessions): void
    {
        $sessions->loadMissing('cycleDay.cycle');

        $setsBySession = SetLog::query()
            ->whereIn('session_id', $sessions->modelKeys())
            ->get(['session_id', 'exercise_id', 'weight_kg', 'reps'])
            ->groupBy('session_id');

        $baselines = $this->baselines($sessions);
        $baselineVolumes = $this->volumes($baselines->pluck('id')->all());

        foreach ($sessions as $session) {
            $sets = $setsBySession->get($session->id, collect());
            $volume = $this->volume($sets);
            $baselineId = $baselines->get($this->baselineKey($session))?->id;

            $session->setRelation('summary', new SessionSummaryData(
                exercisesCount: $sets->pluck('exercise_id')->unique()->count(),
                setsCount: $sets->count(),
                totalVolumeKg: $volume,
                durationSeconds: $session->completed_at !== null
                    ? (int) round($session->started_at->diffInSeconds($session->completed_at))
                    : null,
                volumeChangePct: $this->changePct($session, $volume, $baselineVolumes->get($baselineId)),
            ));
        }
    }

    /**
     * The baseline session of each completed planned session in cycle 2+, keyed
     * by `"{routine_id}:{cycle sequence - 1}:{day order}"`. Only the most
     * recent completed session of each key is kept.
     *
     * @param  EloquentCollection<int, TrainingSession>  $sessions
     * @return Collection<string, stdClass>
     */
    private function baselines(EloquentCollection $sessions): Collection
    {
        $comparable = $sessions->filter(fn (TrainingSession $session): bool => $this->comparesToBaseline($session));

        return DB::table('training_sessions as t')
            ->join('cycle_days as d', 'd.id', '=', 't.cycle_day_id')
            ->join('cycles as c', 'c.id', '=', 'd.cycle_id')
            ->where('t.status', SessionStatus::Completed->value)
            ->whereIn('t.routine_id', $comparable->pluck('routine_id')->unique()->all())
            ->whereIn('c.sequence_number', $comparable->map(fn (TrainingSession $session): int => $session->cycleDay->cycle->sequence_number - 1)->unique()->all())
            ->whereIn('d.order', $comparable->map(fn (TrainingSession $session): int => $session->cycleDay->order)->unique()->all())
            ->orderByDesc('t.started_at')
            ->orderByDesc('t.id')
            ->get(['t.id', 't.routine_id', 'c.sequence_number', 'd.order'])
            ->unique(fn (stdClass $row): string => "{$row->routine_id}:{$row->sequence_number}:{$row->order}")
            ->keyBy(fn (stdClass $row): string => "{$row->routine_id}:{$row->sequence_number}:{$row->order}");
    }

    private function comparesToBaseline(TrainingSession $session): bool
    {
        return $session->status === SessionStatus::Completed
            && $session->cycleDay !== null
            && $session->cycleDay->cycle->sequence_number > 1;
    }

    private function baselineKey(TrainingSession $session): string
    {
        if (! $this->comparesToBaseline($session)) {
            return '';
        }

        return "{$session->routine_id}:".($session->cycleDay->cycle->sequence_number - 1).":{$session->cycleDay->order}";
    }

    /**
     * @param  list<int>  $sessionIds
     * @return Collection<int, float>
     */
    private function volumes(array $sessionIds): Collection
    {
        return SetLog::query()
            ->whereIn('session_id', $sessionIds)
            ->get(['session_id', 'weight_kg', 'reps'])
            ->groupBy('session_id')
            ->map(fn (Collection $sets): float => $this->volume($sets));
    }

    /**
     * @param  Collection<int, SetLog>  $sets
     */
    private function volume(Collection $sets): float
    {
        return round($sets->sum(fn (SetLog $set): float => (float) $set->weight_kg * $set->reps), 2);
    }

    private function changePct(TrainingSession $session, float $volume, ?float $baselineVolume): ?float
    {
        if (! $this->comparesToBaseline($session) || $baselineVolume === null || $baselineVolume == 0.0) {
            return null;
        }

        return round(($volume - $baselineVolume) / $baselineVolume * 100, 1);
    }
}
