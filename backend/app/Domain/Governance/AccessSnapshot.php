<?php

namespace App\Domain\Governance;

/**
 * What OptiNexus said about one user in one tenant at one moment, for OptiFleet's own permission names. It is only ever used
 * while `expiresAt` lies in the future; an expired snapshot is never read as a grant.
 */
final class AccessSnapshot
{
    /**
     * @param  array<string, string>  $permissions  local permission name => OptiNexus scope of the role that grants it
     */
    public function __construct(
        public readonly bool $sessionAllowed,
        public readonly ?string $denyReason,
        public readonly array $permissions,
        public readonly int $fetchedAt,
        public readonly int $expiresAt,
    ) {}

    public function isFresh(?int $now = null): bool
    {
        return $this->expiresAt > ($now ?? now()->getTimestamp());
    }

    public function has(string $permission): bool
    {
        return $this->sessionAllowed && array_key_exists($permission, $this->permissions);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['session_allowed' => $this->sessionAllowed, 'deny_reason' => $this->denyReason, 'permissions' => $this->permissions,
            'fetched_at' => $this->fetchedAt, 'expires_at' => $this->expiresAt];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((bool) $data['session_allowed'], $data['deny_reason'] ?? null, (array) $data['permissions'], (int) $data['fetched_at'], (int) $data['expires_at']);
    }

    public static function denied(string $reason, int $ttl = 30): self
    {
        $now = now()->getTimestamp();

        return new self(false, $reason, [], $now, $now + $ttl);
    }
}
