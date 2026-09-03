<?php

namespace App\Actions\Session;

use App\Data\Session\UpdateSetLogData;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Session\SessionAlreadyCompletedException;
use App\Models\SetLog;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

final class SetLogUpdateAction
{
    public function handle(TrainingSession $session, SetLog $set, UpdateSetLogData $data): SetLog
    {
        $this->ensureSessionOpen($session);

        DB::transaction(fn (): bool => $set->update([
            'weight_kg' => $data->weight_kg,
            'reps' => $data->reps,
            'rpe' => $data->rpe,
            'note' => $data->note,
        ]));

        return $set->load('exercise');
    }

    private function ensureSessionOpen(TrainingSession $session): void
    {
        throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException);
    }
}
