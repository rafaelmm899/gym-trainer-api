<?php

namespace App\Actions\Session;

use App\Data\Session\CompleteSessionData;
use App\Data\Session\CreateTrainingSessionData;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Cycle\CycleDayImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class TrainingSessionImportAction
{
    public function __construct(
        private CycleDayImportService $import,
        private TrainingSessionCreateAction $open,
        private SetLogCreateAction $logSet,
        private SessionCloseAction $close,
    ) {}

    public function handle(User $user, Routine $routine, CycleDay $day, UploadedFile $file): TrainingSession
    {
        // Reading and validating the workbook does no writes, so it stays
        // outside the transaction — no DB transaction sits open for the
        // duration of a file-parsing pass.
        $rows = $this->import->handle($routine, $day, $file);

        return DB::transaction(function () use ($user, $routine, $day, $rows): TrainingSession {
            $session = $this->open->handle($user, $routine, CreateTrainingSessionData::from(['day' => $day->uuid]));

            foreach ($rows as $row) {
                $this->logSet->handle($session, $row);
            }

            return $this->close->handle($session, CompleteSessionData::from([]));
        });
    }
}
