<?php

namespace App\Domain\Dashboard\Widgets\Procurement;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PR-01 Procurement Pipeline — open Purchase Requests (needs purchase_request.view) and open Purchase
 * Orders (needs purchase_order.view) by stage. A section the user may not see is not computed at all.
 * Records are scoped by their warehouse; a PR without a warehouse is visible to tenant-wide users only.
 */
class ProcurementPipelineWidget extends Widget
{
    public const PR_STATUSES = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED'];

    public const PO_STATUSES = ['DRAFT', 'SUBMITTED', 'PENDING_APPROVAL', 'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED'];

    public function id(): string
    {
        return 'PR-01';
    }

    public function modules(): array
    {
        return ['PROCUREMENT'];
    }

    public function anyPermissions(): array
    {
        return ['purchase_request.view', 'purchase_order.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    public function compute(DashboardContext $context): array
    {
        $data = ['purchase_requests' => null, 'purchase_orders' => null, 'goods_receipt_pending' => null];
        if ($context->can('purchase_request.view')) {
            $data['purchase_requests'] = self::countsByKey($this->requests($context), 'pr.status', self::PR_STATUSES);
        }
        if ($context->can('purchase_order.view')) {
            $data['purchase_orders'] = self::countsByKey($this->orders($context), 'po.status', self::PO_STATUSES);
            $data['goods_receipt_pending'] = $data['purchase_orders']['ISSUED'] + $data['purchase_orders']['PARTIALLY_RECEIVED'];
        }

        return ['data' => $data];
    }

    protected function requests(DashboardContext $context): Builder
    {
        $query = DB::table('purchase_requests as pr')->where('pr.tenant_id', $context->tenantId)->whereIn('pr.status', self::PR_STATUSES);

        return $context->scopeWarehouse($query, 'pr.warehouse_id');
    }

    protected function orders(DashboardContext $context): Builder
    {
        $query = DB::table('purchase_orders as po')->where('po.tenant_id', $context->tenantId)->whereIn('po.status', self::PO_STATUSES);

        return $context->scopeWarehouse($query, 'po.delivery_warehouse_id');
    }
}
