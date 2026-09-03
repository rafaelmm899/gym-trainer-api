<?php

namespace App\Enums\Cycle;

/**
 * The lifecycle of a weekly cycle.
 *
 * The first cycle is born {@see self::Active} — generated synchronously inside
 * `POST /api/v1/routines`; there is no "activate a cycle" step in the MVP.
 * {@see self::Generating} / {@see self::Failed} belong to the asynchronous
 * on-demand generation of cycle N+1; the rollover of that job moves the outgoing
 * cycle to {@see self::Completed} (all days trained) or {@see self::Incomplete}
 * (the next cycle was generated before the week was finished). {@see self::Draft}
 * is reserved for a future "review before confirming" flow and is unused today.
 */
enum CycleStatus: string
{
    case Generating = 'generating';
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Incomplete = 'incomplete';
    case Failed = 'failed';
}
