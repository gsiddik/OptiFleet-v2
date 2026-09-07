<?php

namespace App\Domain\Shared\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Enforces tenant isolation at the query layer. Applied automatically to
 * every model using the BelongsToTenant trait. If a tenant context is bound
 * (request went through TenantContextMiddleware), every query is filtered to
 * that tenant_id — this holds even if a controller/service forgets to scope
 * explicitly, which is the defense-in-depth requirement for Phase 1.
 *
 * When no tenant context is bound (e.g. platform-scope console/tests), the
 * scope is a no-op so platform-level code can still operate.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->hasTenant()) {
            $builder->where($model->getTable().'.tenant_id', $context->tenantId());
        }
    }
}
