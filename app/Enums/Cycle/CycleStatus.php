<?php

namespace App\Enums\Cycle;

/**
 * The lifecycle of a weekly cycle.
 *
 * The synchronous first-cycle path only ever writes {@see self::Draft}; cycle
 * activation (a later story) adds {@see self::Active} / {@see self::Completed};
 * {@see self::Generating} / {@see self::Failed} belong to the asynchronous
 * on-demand generation of cycle N+1.
 */
enum CycleStatus: string
{
    case Generating = 'generating';
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
}
