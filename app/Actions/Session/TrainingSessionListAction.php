<?php

namespace App\Actions\Session;

use App\Enums\Session\SessionStatus;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Services\Session\SessionSummaryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class TrainingSessionListAction
{
    public function __construct(private SessionSummaryService $summaries) {}

    /**
     * @return LengthAwarePaginator<int, TrainingSession>
     */
    public function handle(Routine $routine, ?string $cycleDay, ?SessionStatus $status, int $perPage): LengthAwarePaginator
    {
        $sessions = $routine->trainingSessions()
            ->with('cycleDay.cycle')
            ->when($cycleDay, fn ($query, string $uuid) => $query->whereHas('cycleDay', fn ($day) => $day->where('uuid', $uuid)))
            ->when($status, fn ($query, SessionStatus $status) => $query->where('status', $status))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        /** @var Collection<int, TrainingSession> $page */
        $page = $sessions->getCollection();
        $this->summaries->attach($page);

        return $sessions;
    }
}
