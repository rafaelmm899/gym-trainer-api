<?php

namespace App\Enums\Session;

/**
 * The state of the AI analysis that runs when a session is completed: `pending`
 * until the job is queued, `processing` while it runs, then `done` or `failed`.
 * `failed` is retryable without touching the session's own status. A session is
 * born `pending`; only the "close session" / analysis stories move it on.
 */
enum AnalysisState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
