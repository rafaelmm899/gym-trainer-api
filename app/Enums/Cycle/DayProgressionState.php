<?php

namespace App\Enums\Cycle;

/**
 * How a day of a cycle N+1 got its prescription while the cycle is being
 * generated: `pending` until its job runs, then `ai` (progressed by the AI) or
 * `fallback` (the AI kept failing, so the routine's session recommendations or
 * last week's prescription were used). `unchanged` is a day nothing was
 * trained on — copied as is, never sent to the AI — and the default for every
 * other cycle.
 */
enum DayProgressionState: string
{
    case Pending = 'pending';
    case Ai = 'ai';
    case Fallback = 'fallback';
    case Unchanged = 'unchanged';
}
