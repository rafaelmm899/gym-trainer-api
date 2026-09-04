<?php

namespace App\Services\Session;

use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Exceptions\Session\SessionHasNoSetsException;
use App\Models\TrainingSession;

/**
 * The business invariants of closing a training session, checked in order: it
 * must not already be completed, and it must have at least one set logged.
 * Checking already-completed first means a session forced into both bad
 * states at once always reports the conflict, never the validation error.
 */
final class SessionCompletionService
{
    public function guard(TrainingSession $session): void
    {
        throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException);

        throw_if($session->sets()->doesntExist(), new SessionHasNoSetsException);
    }
}
