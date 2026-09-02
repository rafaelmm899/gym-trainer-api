<?php

namespace App\Policies;

use App\Models\Routine;
use App\Models\User;

class RoutinePolicy
{
    /**
     * Any authenticated user may create a routine for themselves. There is no
     * target resource to scope on; cross-user access is impossible by
     * construction (the routine is always created via `$user->routines()`).
     * The Policy exists to establish the pattern for the by-id routine / cycle
     * abilities in later stories.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * A routine is only ever readable by the user who owns it. Enforced as
     * `->can('view', 'routine')` route middleware on `GET /api/v1/routines/{routine}`,
     * which runs after route-model binding: a missing `uuid` is a 404 before
     * this check, a foreign one a 403 here.
     */
    public function view(User $user, Routine $routine): bool
    {
        return $routine->user_id === $user->id;
    }
}
