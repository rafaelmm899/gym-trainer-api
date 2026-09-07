<?php

namespace App\Services\Cycle;

use App\Enums\Session\SessionStatus;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\TrainingSession;

/**
 * Whether a cycle's week was fully trained: every one of its days has at
 * least one {@see TrainingSession} completed against it. A free session
 * (`cycle_day_id: null`) never counts toward any day.
 */
final class CycleCompletionService
{
    public function wasCompleted(Cycle $cycle): bool
    {
        $cycle->loadMissing('cycleDays');

        return $cycle->cycleDays->every(
            fn (CycleDay $day): bool => TrainingSession::query()
                ->where('cycle_day_id', $day->id)
                ->where('status', SessionStatus::Completed)
                ->exists(),
        );
    }
}
