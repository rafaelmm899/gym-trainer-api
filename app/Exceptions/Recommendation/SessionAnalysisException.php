<?php

namespace App\Exceptions\Recommendation;

use App\Exceptions\Cycle\CycleGenerationException;
use App\Exceptions\DomainException;
use App\Jobs\Session\SessionAnalysisJob;
use App\Services\Recommendation\SessionAnalystService;
use Throwable;

/**
 * The AI analyst could not produce usable recommendations for a completed
 * session — the provider call failed, or the structured response was
 * malformed / out of bounds.
 *
 * Thrown from {@see SessionAnalystService}. Never
 * rendered over HTTP in this ticket — the only caller is
 * {@see SessionAnalysisJob}, which lets it propagate so the
 * queue's own retry mechanism handles it, and moves `analysis_state` to
 * `failed` once retries are exhausted. Kept in the `DomainException` shape for
 * consistency with {@see CycleGenerationException}'s
 * identical "Service throws a typed exception on AI failure" pattern.
 */
final class SessionAnalysisException extends DomainException
{
    protected string $errorCode = 'SESSION_ANALYSIS_FAILED';

    public function __construct(string $message = 'The session analysis could not be completed.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
