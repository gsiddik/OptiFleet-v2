<?php

namespace App\Domain\Governance\Delegation;

use RuntimeException;

/**
 * A delegation call that did not succeed, already translated to what OptiFleet tells its user: a stable English `errorCode`
 * (the OptiNexus code where there is one), the HTTP status OptiFleet answers with and the translation key of the message.
 * `outcomeUnknown` is true when a change may or may not have been applied (the call did not get an answer): the screen must
 * tell the user to look at the list before trying again.
 */
class DelegationException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>|null  $fieldErrors  Laravel-style field => messages, only for validation answers
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly string $messageKey,
        public readonly ?array $fieldErrors = null,
        public readonly ?string $correlationId = null,
        public readonly bool $retryable = false,
        public readonly bool $outcomeUnknown = false,
        public readonly mixed $details = null,
    ) {
        parent::__construct($errorCode);
    }
}
