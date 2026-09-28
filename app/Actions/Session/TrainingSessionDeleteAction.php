<?php

namespace App\Actions\Session;

use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

final class TrainingSessionDeleteAction
{
    public function handle(TrainingSession $session): void
    {
        DB::transaction(function () use ($session): void {
            throw_unless($session->status === SessionStatus::InProgress, new SessionAlreadyCompletedException);

            $session->delete();
        });
    }
}
