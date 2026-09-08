<?php

namespace App\Domain\Analytics\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Mongo-collection equivalent of App\Domain\Shared\Scopes\TenantScope.
 *
 * The PostgreSQL TenantScope filters on "{table}.tenant_id" because SQL
 * queries can join across tables. MongoDB has no joins/table-qualified
 * columns — filtering on "{$model->getTable()}.tenant_id" against a Mongo
 * collection produces a literal (nonexistent) dotted field name and
 * silently matches nothing, which would be a tenant-isolation bug, not
 * just a missed filter. This scope is otherwise identical: a no-op when no
 * tenant is bound (platform/console context), enforced defense-in-depth on
 * every query via the model's global scope.
 */
class AnalyticsTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasTenant()) {
            $builder->where('tenant_id', $context->tenantId());
        }
    }
}
