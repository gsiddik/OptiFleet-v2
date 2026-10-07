<?php

namespace App\Domain\Dashboard\Widgets\Maintenance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** MT-01 Maintenance Schedule Status — open schedules by their computed status (segmented bar). */
class ScheduleStatusWidget extends Widget
{
    public const STATUSES = ['UPCOMING', 'DUE_SOON', 'DUE', 'OVERDUE'];

    public function id(): string
    {
        return 'MT-01';
    }

    public function modules(): array
    {
        return ['MAINTENANCE'];
    }

    public function permissions(): array
    {
        return ['maintenance_schedule.view'];
    }

    public function compute(DashboardContext $context): array
    {
        $counts = self::countsByKey($this->schedules($context), 's.status', self::STATUSES);

        return ['data' => ['total' => array_sum($counts), 'by_status' => $counts]];
    }

    public function detailRules(): ?array
    {
        return ['status' => ['nullable', 'in:'.implode(',', self::STATUSES)]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->rows($this->schedules($context))
            ->when($params['status'] ?? null, fn ($q, $s) => $q->where('s.status', $s))
            ->orderByRaw('s.next_due_date asc nulls last');

        return $this->paginate($query, $params, fn ($r) => $this->present($r, $context));
    }

    protected function schedules(DashboardContext $context): Builder
    {
        $query = DB::table('maintenance_schedules as s')
            ->join('vehicles as v', 'v.id', '=', 's.vehicle_id')
            ->where('s.tenant_id', $context->tenantId)
            ->whereNull('v.deleted_at')->where('v.status', '!=', 'DISPOSED')
            ->whereIn('s.status', self::STATUSES);

        return $context->scopeBranch($query, 'v.branch_id');
    }

    protected function rows(Builder $query): Builder
    {
        return $query->leftJoin('maintenance_packages as p', 'p.id', '=', 's.maintenance_package_id')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->select(['s.id', 's.status', 's.next_due_date', 's.next_due_odometer', 's.next_due_engine_hour', 'v.id as vehicle_id',
                'v.registration_number', 'v.current_odometer', 'v.engine_hour', 'b.name as branch_name', 'p.name as package_name']);
    }

    protected function present(object $r, DashboardContext $context): array
    {
        $daysOverdue = $r->next_due_date === null ? null : self::daysSince($context, $r->next_due_date);
        $kmOver = $r->next_due_odometer === null || $r->current_odometer === null ? null
            : self::decimal(BigDecimal::of((string) $r->current_odometer)->minus((string) $r->next_due_odometer), 0);

        return [
            'id' => $r->id, 'status' => $r->status, 'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number,
            'branch_name' => $r->branch_name, 'package_name' => $r->package_name, 'next_due_date' => $r->next_due_date,
            'next_due_odometer' => $r->next_due_odometer === null ? null : self::decimal($r->next_due_odometer, 0),
            'current_odometer' => $r->current_odometer === null ? null : self::decimal($r->current_odometer, 0),
            'days_overdue' => $daysOverdue !== null && $daysOverdue > 0 ? $daysOverdue : 0,
            'km_over' => $kmOver !== null && (float) $kmOver > 0 ? $kmOver : '0',
        ];
    }
}
