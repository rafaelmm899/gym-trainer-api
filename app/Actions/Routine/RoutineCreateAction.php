<?php

namespace App\Actions\Routine;

use App\Data\Routine\RoutineData;
use App\Enums\Routine\RoutineStatus;
use App\Exceptions\Profile\ProfileIncompleteException;
use App\Models\AthleteProfile;
use App\Models\Routine;
use App\Models\User;
use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;
use Illuminate\Support\Facades\DB;

final class RoutineCreateAction
{
    public function __construct(
        private CyclePlannerService $planner,
        private CycleDraftService $cycleDraft,
    ) {}

    public function handle(User $user, RoutineData $data): Routine
    {
        $profile = $this->ensureOnboardingComplete($user);

        // Plan the first cycle before opening a transaction: if the AI call
        // fails, nothing is written — no routine row, and the incumbent active
        // routine is left untouched. The client retries by re-sending the POST.
        $plan = $this->planner->planFirstCycle($profile, $data->goal, $data->hint);

        return DB::transaction(function () use ($user, $data, $plan): Routine {
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

            $this->cycleDraft->persist($routine, $plan);

            return $routine->load('cycle.cycleDays.dayExercises.exercise');
        });
    }

    /**
     * The AI cycle planner is fed the athlete profile, so onboarding must be
     * done first. A state precondition, not input shape — hence 409, not 422.
     * Returns the profile so the caller can pass it straight to the planner
     * without a second query (and without a strict-mode lazy load).
     */
    private function ensureOnboardingComplete(User $user): AthleteProfile
    {
        $profile = $user->athleteProfile()->first();

        throw_if($profile === null, new ProfileIncompleteException);

        return $profile;
    }
}
