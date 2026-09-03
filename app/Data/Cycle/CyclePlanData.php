<?php

namespace App\Data\Cycle;

use App\Services\Cycle\CycleDraftService;
use App\Services\Cycle\CyclePlannerService;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * A full first-cycle plan: the split rationale and exactly five planned days.
 * Produced by {@see CyclePlannerService} from the AI's
 * structured output; consumed by {@see CycleDraftService}.
 */
final class CyclePlanData extends Data
{
    /**
     * @param  list<CyclePlanDayData>  $days
     */
    public function __construct(
        public readonly string $splitRationale,
        #[DataCollectionOf(CyclePlanDayData::class)]
        public readonly array $days,
    ) {}
}
