<?php

namespace App\Actions\Routine;

use App\Data\Routine\RoutineData;
use App\Enums\Routine\RoutineStatus;
use App\Exceptions\Profile\ProfileIncompleteException;
use App\Jobs\Cycle\GenerateCycleJob;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RoutineCreateAction
{
    public function handle(User $user, RoutineData $data): Routine
    {
        $this->ensureOnboardingComplete($user);

        return DB::transaction(function () use ($user, $data): Routine {
            // Archive the incumbent active routine, permanently, before the new
            // one takes the slot — so the partial unique index is never hit.
            $user->routines()
                ->where('status', RoutineStatus::Active)
                ->update([
                    'status' => RoutineStatus::Archived->value,
                    'archived_at' => now(),
                ]);

            $routine = $user->routines()->create([
                'name' => $data->name,
                'goal' => $data->goal,
                'hint' => $data->hint,
                'status' => RoutineStatus::Active,
            ]);

            GenerateCycleJob::dispatch($routine);

            return $routine;
        });
    }

    /**
     * The AI cycle planner is fed the athlete profile, so onboarding must be
     * done first. A state precondition, not input shape — hence 409, not 422.
     */
    private function ensureOnboardingComplete(User $user): void
    {
        throw_if($user->athleteProfile()->doesntExist(), new ProfileIncompleteException);
    }
}
