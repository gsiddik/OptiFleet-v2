<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** FL-01 Fleet Status — vehicles by current status (DISPOSED vehicles are not part of the fleet). */
class FleetStatusWidget extends Widget
{
    public const STATUSES = ['ACTIVE', 'IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE'];

    public function id(): string
    {
        return 'FL-01';
    }

    public function modules(): array
    {
        return ['VEHICLE'];
    }

    public function permissions(): array
    {
        return ['vehicle.view'];
    }

    public function compute(DashboardContext $context): array
    {
        $counts = self::countsByKey($this->vehicles($context), 'v.status', self::STATUSES);

        return ['data' => ['total' => array_sum($counts), 'by_status' => $counts]];
    }

    public function detailRules(): ?array
    {
        // Branch drill-down (FL-02) uses the validated global branch filter, not a separate parameter.
        return ['status' => ['nullable', 'in:'.implode(',', self::STATUSES)]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->vehicles($context)
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->when($params['status'] ?? null, fn ($q, $s) => $q->where('v.status', $s))
            ->orderBy('v.registration_number')
            ->select(['v.id', 'v.registration_number', 'v.brand', 'v.model', 'v.status', 'v.branch_id', 'b.name as branch_name']);

        return $this->paginate($query, $params);
    }

    protected function vehicles(DashboardContext $context): Builder
    {
        $query = DB::table('vehicles as v')->where('v.tenant_id', $context->tenantId)->whereNull('v.deleted_at')
            ->whereIn('v.status', self::STATUSES);

        return $context->scopeBranch($query, 'v.branch_id');
    }
}
