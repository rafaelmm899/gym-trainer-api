<?php

namespace App\Http\Controllers\Cycle;

use App\Models\CycleDay;
use App\Models\Routine;
use App\Services\Cycle\CycleDayExportService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExportCycleDayController
{
    public function __invoke(Routine $routine, CycleDay $day, CycleDayExportService $service): BinaryFileResponse
    {
        $sheet = $service->handle($routine, $day);

        return Excel::download($sheet, $sheet->filename);
    }
}
