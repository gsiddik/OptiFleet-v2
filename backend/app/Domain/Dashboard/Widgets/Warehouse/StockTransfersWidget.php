<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-03 Stock Transfers — open transfers by status, transfers in transit (age since dispatched_at)
 * and transfers received in the last 30 days with a discrepancy (received < sent, damaged or lost).
 * A transfer is visible when its source or destination warehouse is in scope (Stock Transfer list rule).
 */
class StockTransfersWidget extends Widget
{
    public const OPEN_STATUSES = ['DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED'];

    public const TRANSIT_STATUSES = ['DISPATCHED', 'IN_TRANSIT'];

    /** In transit for more than this many days is flagged. */
    public const TRANSIT_ALERT_DAYS = 7;

    public const DISCREPANCY_WINDOW_DAYS = 30;

    public function id(): string
    {
        return 'WH-03';
    }

    public function modules(): array
    {
        return ['INVENTORY'];
    }

    public function permissions(): array
    {
        return ['stock_transfer.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    public function compute(DashboardContext $context): array
    {
        $open = self::countsByKey($this->transfers($context)->whereIn('st.status', self::OPEN_STATUSES), 'st.status', self::OPEN_STATUSES);
        $transit = $this->transfers($context)->whereIn('st.status', self::TRANSIT_STATUSES);
        $transitCount = (clone $transit)->count();
        $transitLate = (clone $transit)->where('st.dispatched_at', '<', $context->now->utc()->subDays(self::TRANSIT_ALERT_DAYS)->format('Y-m-d H:i:s'))->count();
        $discrepancies = $this->discrepancies($context)->count();

        return ['data' => [
            'open_total' => array_sum($open), 'open_by_status' => $open,
            'in_transit' => $transitCount, 'in_transit_over_threshold' => $transitLate, 'threshold_days' => self::TRANSIT_ALERT_DAYS,
            'received_with_discrepancy' => $discrepancies, 'discrepancy_window_days' => self::DISCREPANCY_WINDOW_DAYS,
        ]];
    }

    public function detailRules(): ?array
    {
        return ['view' => ['required', 'in:open,in_transit,discrepancy']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = match ($params['view']) {
            'open' => $this->transfers($context)->whereIn('st.status', self::OPEN_STATUSES)->orderBy('st.created_at'),
            'in_transit' => $this->transfers($context)->whereIn('st.status', self::TRANSIT_STATUSES)->orderBy('st.dispatched_at'),
            'discrepancy' => $this->discrepancies($context)->orderByDesc('st.received_at'),
        };
        $query->leftJoin('warehouses as wf', 'wf.id', '=', 'st.from_warehouse_id')
            ->leftJoin('warehouses as wt', 'wt.id', '=', 'st.to_warehouse_id')
            ->select(['st.id', 'st.transfer_number', 'st.status', 'st.created_at', 'st.dispatched_at', 'st.received_at',
                'wf.name as from_warehouse', 'wt.name as to_warehouse']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'transfer_number' => $r->transfer_number, 'status' => $r->status,
            'from_warehouse' => $r->from_warehouse, 'to_warehouse' => $r->to_warehouse,
            'created_at' => self::isoUtc($r->created_at), 'dispatched_at' => self::isoUtc($r->dispatched_at), 'received_at' => self::isoUtc($r->received_at),
            'days_in_transit' => $r->dispatched_at && in_array($r->status, self::TRANSIT_STATUSES, true)
                ? (int) CarbonImmutable::parse($r->dispatched_at, 'UTC')->diffInDays($context->now, false) : null,
        ]);
    }

    private function discrepancies(DashboardContext $context): Builder
    {
        return $this->transfers($context)->whereIn('st.status', ['RECEIVED', 'COMPLETED'])
            ->where('st.received_at', '>=', $context->now->utc()->subDays(self::DISCREPANCY_WINDOW_DAYS)->format('Y-m-d H:i:s'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('stock_transfer_items as sti')->whereColumn('sti.stock_transfer_id', 'st.id')
                ->where(fn ($w) => $w->whereColumn('sti.quantity_received', '<', 'sti.quantity_sent')
                    ->orWhere('sti.quantity_damaged', '>', 0)->orWhere('sti.quantity_lost', '>', 0)));
    }

    private function transfers(DashboardContext $context): Builder
    {
        $query = DB::table('stock_transfers as st')->where('st.tenant_id', $context->tenantId);
        $none = ['00000000-0000-0000-0000-000000000000'];
        if ($context->warehouseIds !== null) {
            $ids = $context->warehouseIds ?: $none;
            $query->where(fn ($q) => $q->whereIn('st.from_warehouse_id', $ids)->orWhereIn('st.to_warehouse_id', $ids));
        }
        if ($context->filters->warehouseId !== null) {
            $id = $context->filters->warehouseId;
            $query->where(fn ($q) => $q->where('st.from_warehouse_id', $id)->orWhere('st.to_warehouse_id', $id));
        }
        if ($context->filters->branchId !== null) {
            $branchWarehouses = fn ($q) => $q->select('id')->from('warehouses')->where('tenant_id', $context->tenantId)->where('branch_id', $context->filters->branchId);
            $query->where(fn ($q) => $q->whereIn('st.from_warehouse_id', $branchWarehouses)->orWhereIn('st.to_warehouse_id', $branchWarehouses));
        }

        return $query;
    }
}
