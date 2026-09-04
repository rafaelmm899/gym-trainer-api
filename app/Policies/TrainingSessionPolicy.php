<?php

namespace App\Policies;

use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;

class TrainingSessionPolicy
{
    /**
     * A user may open a training session only under a routine they own.
     * Enforced through `StoreTrainingSessionRequest::authorize()`
     * (`$user->can('create', [TrainingSession::class, $routine])`), which runs
     * after route-model binding: an unknown `{routine}` uuid is a 404 before
     * this check, a foreign one a 403 here. The routine's `active` / `archived`
     * state is a business rule (a Service guard), not an authorization concern.
     */
    public function create(User $user, Routine $routine): bool
    {
        return $routine->user_id === $user->id;
    }

    /**
     * A user may complete only a training session they own. Enforced through
     * `CompleteTrainingSessionRequest::authorize()`
     * (`$user->can('complete', $this->route('session'))`), which runs after
     * route-model binding: an unknown `{session}` uuid is a 404 before this
     * check, a foreign one a 403 here. Whether the session is already
     * `completed` or has no sets logged is a business rule (a Service guard),
     * not an authorization concern.
     */
    public function complete(User $user, TrainingSession $session): bool
    {
        return $session->user_id === $user->id;
    }
}
