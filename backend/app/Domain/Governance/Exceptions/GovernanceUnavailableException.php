<?php

namespace App\Domain\Governance\Exceptions;

use RuntimeException;

/**
 * OptiNexus (the governance authority) could not give a usable answer: unreachable, timed out, rate limited beyond the bounded
 * wait, or a server error. The caller must deny; there is no local fallback and no use of an answer past its TTL.
 */
class GovernanceUnavailableException extends RuntimeException
{
    public const CODE = 'GOVERNANCE_UNAVAILABLE';

    public function __construct(string $message = 'The authorization service (OptiNexus) is not available.', public readonly ?int $retryAfterSeconds = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
