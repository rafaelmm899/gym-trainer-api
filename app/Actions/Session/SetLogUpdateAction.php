<?php

namespace App\Actions\Session;

use App\Data\Session\UpdateSetLogData;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Services\Session\SetLoggingService;
use Illuminate\Support\Facades\DB;

final class SetLogUpdateAction
{
    public function __construct(private SetLoggingService $sets) {}

    public function handle(TrainingSession $session, SetLog $set, UpdateSetLogData $data): SetLog
    {
        $this->sets->guardOpen($session);

        DB::transaction(fn (): bool => $set->update([
            'weight_kg' => $data->weight_kg,
            'reps' => $data->reps,
            'rpe' => $data->rpe,
            'note' => $data->note,
        ]));

        return $set->load('exercise');
    }
}
