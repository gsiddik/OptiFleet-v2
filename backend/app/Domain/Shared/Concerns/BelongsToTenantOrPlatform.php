<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Scopes\TenantOrPlatformScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenantOrPlatform
{
    public static function bootBelongsToTenantOrPlatform(): void
    {
        static::addGlobalScope(new TenantOrPlatformScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\Tenant::class);
    }

    public function isPlatformOwned(): bool
    {
        return $this->tenant_id === null;
    }
}
