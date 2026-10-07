<?php

namespace App\Domain\Dashboard\Widgets\Tire;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\Tire\Services\TireInventoryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared base of the tire widgets. Tire visibility follows the Tire module's own rule
 * (TireInventoryService::scopeToUser): a tire is visible through the branch of the vehicle it is on,
 * the warehouse it is in, or — when it is in neither (removed, at a vendor) — the branch of the
 * vehicle it was last installed on. Filters only narrow that set.
 */
abstract class TireWidget extends Widget
{
    private const NONE = '00000000-0000-0000-0000-000000000000';

    public function modules(): array
    {
        return ['TIRE'];
    }

    public function permissions(): array
    {
        return ['tire.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    /** Applies the tire visibility rule + filters on a query with a `tires` table alias. */
    protected function scopeTires(Builder $query, DashboardContext $context, string $alias = 't'): Builder
    {
        $lastBranch = TireInventoryService::lastBranchSql($alias);
        if ($context->branchIds !== null || $context->warehouseIds !== null) {
            $branches = $context->branchIds ?: [self::NONE];
            $warehouses = $context->warehouseIds ?: [self::NONE];
            $query->where(fn ($q) => $q
                ->whereIn("{$alias}.current_vehicle_id", DB::table('vehicles')->whereIn('branch_id', $branches)->select('id'))
                ->orWhereIn("{$alias}.current_warehouse_id", $warehouses)
                ->orWhere(fn ($o) => $o->whereNull("{$alias}.current_vehicle_id")->whereNull("{$alias}.current_warehouse_id")
                    ->whereIn(DB::raw($lastBranch), $branches)));
        }
        if ($context->filters->warehouseId !== null) {
            $query->where("{$alias}.current_warehouse_id", $context->filters->warehouseId);
        }
        if (($branch = $context->filters->branchId) !== null) {
            $query->where(fn ($q) => $q
                ->whereIn("{$alias}.current_vehicle_id", DB::table('vehicles')->where('tenant_id', $context->tenantId)->where('branch_id', $branch)->select('id'))
                ->orWhereIn("{$alias}.current_warehouse_id", DB::table('warehouses')->where('tenant_id', $context->tenantId)->where('branch_id', $branch)->select('id'))
                ->orWhere(fn ($o) => $o->whereNull("{$alias}.current_vehicle_id")->whereNull("{$alias}.current_warehouse_id")
                    ->where(DB::raw($lastBranch), $branch)));
        }

        return $query;
    }

    protected function tires(DashboardContext $context): Builder
    {
        return $this->scopeTires(DB::table('tires as t')->where('t.tenant_id', $context->tenantId)->whereNull('t.deleted_at'), $context);
    }
}
