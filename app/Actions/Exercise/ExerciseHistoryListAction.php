<?php

namespace App\Actions\Exercise;

use App\Data\Exercise\ExercisePerformanceData;
use App\Models\Exercise;
use App\Models\User;
use App\Services\Exercise\ExerciseHistoryService;
use Illuminate\Pagination\LengthAwarePaginator;

final class ExerciseHistoryListAction
{
    public function __construct(private ExerciseHistoryService $history) {}

    /**
     * @return LengthAwarePaginator<int, ExercisePerformanceData>
     */
    public function handle(User $user, Exercise $exercise, ?string $routine, int $perPage): LengthAwarePaginator
    {
        return $this->history->pageFor($user, $exercise, $routine, $perPage)->withQueryString();
    }
}
