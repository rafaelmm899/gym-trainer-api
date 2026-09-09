<?php

namespace App\Http\Controllers\Cycle;

use App\Exports\Cycle\CycleDayExport;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Services\Recommendation\RecommendationCatalogService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExportCycleDayController
{
    public function __invoke(Routine $routine, CycleDay $day, RecommendationCatalogService $recommendations): BinaryFileResponse
    {
        $sheet = new CycleDayExport($routine, $day, $recommendations);

        return Excel::download($sheet, $sheet->filename);
    }
}
