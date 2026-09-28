<?php

namespace App\Actions\Session;

use App\Models\TrainingSession;
use App\Services\Session\SessionDeletionService;
use Illuminate\Support\Facades\DB;

final class TrainingSessionDeleteAction
{
    public function __construct(private SessionDeletionService $deletion) {}

    public function handle(TrainingSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $this->deletion->guard($session);

            $session->delete();
        });
    }
}
