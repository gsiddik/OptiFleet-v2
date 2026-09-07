<?php

namespace App\Support;

use App\Models\User;

/**
 * Request-scoped holder for the resolved tenant/user context.
 * Bound as a singleton in the container by TenantContextMiddleware.
 * Eloquent's tenant global scope reads this to enforce isolation
 * even when a controller forgets to filter explicitly.
 */
class TenantContext
{
    private ?string $tenantId = null;

    private ?User $user = null;

    private bool $isPlatformContext = false;

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function setPlatformContext(bool $value): void
    {
        $this->isPlatformContext = $value;
    }

    public function isPlatformContext(): bool
    {
        return $this->isPlatformContext;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }
}
