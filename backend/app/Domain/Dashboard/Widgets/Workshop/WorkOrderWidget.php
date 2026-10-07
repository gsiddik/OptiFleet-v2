<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared base of the Work Order widgets: same access rule as the Work Order list (workshop scope). */
abstract class WorkOrderWidget extends Widget
{
    /** Open Work Orders: submitted and not yet completed/closed/cancelled/rejected. */
    public const OPEN_STATUSES = ['SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'EXTERNAL', 'REWORK', 'QC_PENDING'];

    public function modules(): array
    {
        return ['WORK_ORDER'];
    }

    public function permissions(): array
    {
        return ['work_order.view'];
    }

    public function filters(): array
    {
        return ['branch', 'workshop'];
    }

    protected function workOrders(DashboardContext $context): Builder
    {
        $query = DB::table('work_orders as wo')->where('wo.tenant_id', $context->tenantId)->whereNull('wo.deleted_at');

        return $context->scopeWorkOrder($query, 'wo.workshop_id', 'wo.branch_id');
    }

    protected function withListColumns(Builder $query): Builder
    {
        return $query->leftJoin('vehicles as v', 'v.id', '=', 'wo.vehicle_id')
            ->leftJoin('workshops as ws', 'ws.id', '=', 'wo.workshop_id')
            ->addSelect(['wo.id', 'wo.wo_number', 'wo.status', 'wo.maintenance_type', 'wo.priority', 'wo.created_at', 'wo.started_at', 'wo.completed_at',
                'v.id as vehicle_id', 'v.registration_number', 'ws.name as workshop_name']);
    }

    protected function presentRow(object $r, DashboardContext $context): array
    {
        return [
            'id' => $r->id, 'wo_number' => $r->wo_number, 'status' => $r->status, 'maintenance_type' => $r->maintenance_type,
            'priority' => $r->priority, 'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number,
            'workshop_name' => $r->workshop_name, 'created_at' => self::isoUtc($r->created_at),
            'started_at' => self::isoUtc($r->started_at), 'completed_at' => self::isoUtc($r->completed_at),
            'age_days' => $this->ageDays($r->created_at, $context),
        ];
    }

    /** Whole tenant-local days since a stored UTC timestamp. */
    protected function ageDays(?string $timestamp, DashboardContext $context): ?int
    {
        if ($timestamp === null) {
            return null;
        }
        $local = CarbonImmutable::parse($timestamp, 'UTC')->setTimezone($context->timezone)->startOfDay();

        return (int) $local->diffInDays($context->today(), false);
    }
}
