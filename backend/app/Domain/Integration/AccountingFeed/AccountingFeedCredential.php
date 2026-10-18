<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Read-only machine credential for the accounting feed, bound to exactly one tenant and, when the tenant is linked to OptiNexus,
 * to that tenant's OptiNexus identity (the pairing, audit A19). Only the SHA-256 of the token is stored; the plain token is shown
 * once, when it is issued or rotated.
 */
class AccountingFeedCredential extends Model
{
    use HasUuids;

    public const SCOPE_READ = 'accounting.feed.read';

    public const TOKEN_PREFIX = 'ofaf_';

    protected $fillable = [
        'tenant_id', 'nexus_tenant_id', 'consumer_application', 'name', 'issued_by', 'token_prefix', 'token_hash', 'scopes',
        'expires_at', 'revoked_at', 'rotated_from_id', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * The OptiNexus identity of the tenant is copied into the credential: if the tenant is later linked to another OptiNexus tenant,
     * this credential stops working (it was issued for the earlier pairing).
     *
     * @return array{0: self, 1: string} the stored credential and the plain token (not recoverable later)
     */
    public static function issue(Tenant $tenant, string $name, ?\DateTimeInterface $expiresAt = null, ?string $issuedBy = null, ?self $replaces = null): array
    {
        $plain = self::TOKEN_PREFIX.Str::random(48);

        $credential = self::query()->create([
            'tenant_id' => $tenant->id,
            'nexus_tenant_id' => $tenant->optinexus_tenant_id,
            'consumer_application' => $tenant->optinexus_tenant_id ? (string) config('optinexus.feed.consumer_application') : null,
            'name' => $name,
            'issued_by' => $issuedBy,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => self::hash($plain),
            'scopes' => [self::SCOPE_READ],
            'expires_at' => $expiresAt,
            'rotated_from_id' => $replaces?->id,
        ]);

        return [$credential, $plain];
    }

    /** Ends the credential now (or at `$at`, to let a consumer swap secrets). Idempotent: an earlier end is never postponed. */
    public function end(?string $reason = null, ?\DateTimeInterface $at = null): void
    {
        $at ??= now();
        if ($at <= now()) {
            $this->revoked_at ??= now();
            $this->revoked_reason ??= $reason;
        } elseif ($this->expires_at === null || $this->expires_at > $at) {
            $this->expires_at = $at;
            $this->revoked_reason ??= $reason;
        }
        $this->save();
    }

    public static function findByToken(string $plain): ?self
    {
        return str_starts_with($plain, self::TOKEN_PREFIX)
            ? self::query()->where('token_hash', self::hash($plain))->first()
            : null;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
