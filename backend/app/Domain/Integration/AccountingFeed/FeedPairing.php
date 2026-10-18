<?php

namespace App\Domain\Integration\AccountingFeed;

/** The outcome of FeedPairingGate: allowed (with the pairing that was verified), not enforced (legacy), or a refusal with its code. */
final class FeedPairing
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $enforced,
        public readonly ?int $status = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $nexusTenantId = null,
        public readonly ?string $producer = null,
        public readonly ?string $consumer = null,
    ) {}

    public static function notEnforced(): self
    {
        return new self(true, false);
    }

    public static function allowed(string $nexusTenantId, string $producer, string $consumer): self
    {
        return new self(true, true, nexusTenantId: $nexusTenantId, producer: $producer, consumer: $consumer);
    }

    public static function denied(int $status, string $code, string $message, ?int $retryAfter = null): self
    {
        return new self(false, true, $status, $code, $message, $retryAfter);
    }

    /** @return ?array{nexus_tenant_id: string, producer_application: string, consumer_application: string} */
    public function describe(): ?array
    {
        return $this->enforced && $this->allowed
            ? ['nexus_tenant_id' => (string) $this->nexusTenantId, 'producer_application' => (string) $this->producer, 'consumer_application' => (string) $this->consumer]
            : null;
    }
}
