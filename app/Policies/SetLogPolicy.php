<?php

namespace App\Policies;

use App\Models\TrainingSession;
use App\Models\User;

class SetLogPolicy
{
    /**
     * A user may log or correct a set only under a training session they own.
     * Both abilities gate on the session: `{set}` is scope-bound to `{session}`
     * in the route, so owning the session is owning its sets. Wired through the
     * Form Requests (`$user->can('create'|'update', [SetLog::class, $session])`),
     * which run after route-model binding — an unknown id is a 404 first, a
     * foreign one a 403 here. Whether the session is still `in_progress` is a
     * business rule (a service guard), not an authorization concern.
     */
    public function create(User $user, TrainingSession $session): bool
    {
        return $session->user_id === $user->id;
    }

    public function update(User $user, TrainingSession $session): bool
    {
        return $session->user_id === $user->id;
    }
}
