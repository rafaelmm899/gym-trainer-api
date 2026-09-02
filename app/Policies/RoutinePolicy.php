<?php

namespace App\Policies;

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
}
