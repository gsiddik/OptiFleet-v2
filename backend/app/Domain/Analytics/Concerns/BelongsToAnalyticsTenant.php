<?php

namespace App\Domain\Analytics\Concerns;

use App\Domain\Analytics\Scopes\AnalyticsTenantScope;
use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mongo-model equivalent of App\Domain\Shared\Concerns\BelongsToTenant
 * (see AnalyticsTenantScope for why a separate scope is required). The
 * tenant() relation is unaffected — Eloquent relations query the *related*
 * model's own connection (Tenant defaults to pgsql), so a cross-connection
 * belongsTo works without joins.
 */
trait BelongsToAnalyticsTenant
{
    public static function bootBelongsToAnalyticsTenant(): void
    {
        static::addGlobalScope(new AnalyticsTenantScope);

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
        return $this->belongsTo(Tenant::class);
    }
}
