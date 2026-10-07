<?php

namespace App\Domain\Dashboard\Widgets\Procurement;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** PR-02 Late Purchase Orders — issued / partially received POs past their expected delivery date. */
class LatePurchaseOrdersWidget extends Widget
{
    public function id(): string
    {
        return 'PR-02';
    }

    public function modules(): array
    {
        return ['PROCUREMENT'];
    }

    public function permissions(): array
    {
        return ['purchase_order.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    public function compute(DashboardContext $context): array
    {
        $count = $this->late($context)->count();
        $items = $this->rows($context)->limit(10)->get()->map(fn ($r) => $this->present($r, $context))->all();

        return ['data' => ['count' => $count, 'items' => $items]];
    }

    public function detailRules(): ?array
    {
        return ['partner_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->rows($context)->when($params['partner_id'] ?? null, fn ($q, $p) => $q->where('po.partner_id', $p));

        return $this->paginate($query, $params, fn ($r) => $this->present($r, $context));
    }

    private function late(DashboardContext $context): Builder
    {
        $query = DB::table('purchase_orders as po')->where('po.tenant_id', $context->tenantId)
            ->whereIn('po.status', ['ISSUED', 'PARTIALLY_RECEIVED'])
            ->where('po.expected_delivery_date', '<', $context->todayDate());

        return $context->scopeWarehouse($query, 'po.delivery_warehouse_id');
    }

    private function rows(DashboardContext $context): Builder
    {
        return $this->late($context)
            ->leftJoin('partners as pa', 'pa.id', '=', 'po.partner_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'po.delivery_warehouse_id')
            ->orderBy('po.expected_delivery_date')->orderBy('po.po_number')
            ->select(['po.id', 'po.po_number', 'po.status', 'po.order_date', 'po.expected_delivery_date', 'po.partner_id',
                'pa.name as vendor_name', 'w.name as warehouse_name']);
    }

    private function present(object $r, DashboardContext $context): array
    {
        return [
            'id' => $r->id, 'po_number' => $r->po_number, 'status' => $r->status, 'order_date' => $r->order_date,
            'expected_delivery_date' => $r->expected_delivery_date, 'days_late' => self::daysSince($context, $r->expected_delivery_date),
            'partner_id' => $r->partner_id, 'vendor_name' => $r->vendor_name, 'warehouse_name' => $r->warehouse_name,
        ];
    }
}
