<?php

namespace App\Domain\Shared\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * For master-data models that may be either platform-owned (tenant_id NULL,
 * visible to every tenant) or tenant-owned (tenant_id = current tenant).
 * When a tenant context is bound, a tenant may see its own rows plus the
 * shared platform rows, but never another tenant's rows.
 */
class TenantOrPlatformScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->hasTenant()) {
            $table = $model->getTable();
            $builder->where(function (Builder $query) use ($table, $context) {
                $query->where("$table.tenant_id", $context->tenantId())
                    ->orWhereNull("$table.tenant_id");
            });
        }
    }
}
