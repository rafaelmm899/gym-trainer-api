<?php

namespace App\Services\Session;

use App\Enums\Cycle\CycleStatus;
use App\Enums\Routine\RoutineStatus;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\CycleDayNotInActiveCycleException;
use App\Exceptions\Session\RoutineNotActiveException;
use App\Exceptions\Session\SessionInProgressException;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\User;

/**
 * The business invariants of opening a training session, checked in order:
 * the routine must be active, a planned `day` must belong to that routine's
 * active cycle, and the user must not already have an open session.
 */
final class TrainingSessionOpeningService
{
    public function guard(User $user, Routine $routine, ?CycleDay $day): void
    {
        throw_unless($routine->status === RoutineStatus::Active, new RoutineNotActiveException);

        if ($day !== null) {
            $cycle = $routine->cycle()->first();

            $dayIsInActiveCycle = $cycle !== null
                && $cycle->status === CycleStatus::Active
                && $day->cycle_id === $cycle->id;

            throw_unless($dayIsInActiveCycle, new CycleDayNotInActiveCycleException);
        }

        throw_if(
            $user->trainingSessions()->where('status', SessionStatus::InProgress)->exists(),
            new SessionInProgressException,
        );
    }
}
