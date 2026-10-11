<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Read-only machine credential for the accounting feed, bound to exactly one tenant. Only the SHA-256
 * of the token is stored; the plain token is shown once, when it is issued.
 */
class AccountingFeedCredential extends Model
{
    use HasUuids;

    public const SCOPE_READ = 'accounting.feed.read';

    public const TOKEN_PREFIX = 'ofaf_';

    protected $fillable = ['tenant_id', 'name', 'token_prefix', 'token_hash', 'scopes', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return array{0: self, 1: string} the stored credential and the plain token (not recoverable later)
     */
    public static function issue(Tenant $tenant, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::TOKEN_PREFIX.Str::random(48);

        $credential = self::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => self::hash($plain),
            'scopes' => [self::SCOPE_READ],
            'expires_at' => $expiresAt,
        ]);

        return [$credential, $plain];
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
