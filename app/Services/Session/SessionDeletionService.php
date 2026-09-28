<?php

namespace App\Services\Session;

use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\TrainingSession;

/**
 * The one business invariant of deleting a training session: it must still be
 * `in_progress`. A `completed` session is permanent history, same guarantee
 * an `archived` routine already has.
 */
final class SessionDeletionService
{
    public function guard(TrainingSession $session): void
    {
        throw_unless($session->status === SessionStatus::InProgress, new SessionAlreadyCompletedException);
    }
}
