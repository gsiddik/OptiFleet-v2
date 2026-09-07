<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! $model->getAttribute('tenant_id')) {
                $context = app(TenantContext::class);
                if ($context->hasTenant()) {
                    $model->setAttribute('tenant_id', $context->tenantId());
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\Tenant::class);
    }
}
