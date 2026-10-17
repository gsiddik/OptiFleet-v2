<?php

namespace App\Domain\Governance\Exceptions;

use RuntimeException;

/**
 * OptiNexus answered, but not with the documented contract (unexpected status or shape). Treated like an outage by callers
 * that authorize (deny), and reported distinctly so a configuration error (unbound service account, missing scope) is visible.
 */
class GovernanceContractException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
