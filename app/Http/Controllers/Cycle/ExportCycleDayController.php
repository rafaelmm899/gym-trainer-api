<?php

namespace App\Http\Controllers\Cycle;

use App\Models\CycleDay;
use App\Models\Routine;
use App\Services\Cycle\CycleDayCsvExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportCycleDayController
{
    public function __invoke(Routine $routine, CycleDay $day, CycleDayCsvExportService $export): StreamedResponse
    {
        $csv = $export->handle($routine, $day);

        return response()->streamDownload(
            fn () => print ($csv['contents']),
            $csv['filename'],
            ['Content-Type' => 'text/csv'],
        );
    }
}
