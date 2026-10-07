<?php

namespace App\Domain\Dashboard\Widgets\Maintenance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** MT-03 Open Maintenance Requests — requests waiting for a decision or a Work Order, by status. */
class OpenRequestsWidget extends Widget
{
    public const STATUSES = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED'];

    public function id(): string
    {
        return 'MT-03';
    }

    public function modules(): array
    {
        return ['MAINTENANCE'];
    }

    public function permissions(): array
    {
        return ['maintenance_request.view'];
    }

    public function compute(DashboardContext $context): array
    {
        $counts = self::countsByKey($this->requests($context), 'mr.status', self::STATUSES);

        return ['data' => ['total' => array_sum($counts), 'by_status' => $counts]];
    }

    public function detailRules(): ?array
    {
        return ['status' => ['nullable', 'in:'.implode(',', self::STATUSES)]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->requests($context)
            ->leftJoin('vehicles as v', 'v.id', '=', 'mr.vehicle_id')
            ->leftJoin('branches as b', 'b.id', '=', 'mr.branch_id')
            ->when($params['status'] ?? null, fn ($q, $s) => $q->where('mr.status', $s))
            ->orderBy('mr.created_at')
            ->select(['mr.id', 'mr.request_number', 'mr.status', 'mr.priority', 'mr.created_at', 'v.id as vehicle_id', 'v.registration_number', 'b.name as branch_name']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'request_number' => $r->request_number, 'status' => $r->status, 'priority' => $r->priority,
            'created_at' => self::isoUtc($r->created_at), 'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number,
            'branch_name' => $r->branch_name,
        ]);
    }

    private function requests(DashboardContext $context): Builder
    {
        $query = DB::table('maintenance_requests as mr')->where('mr.tenant_id', $context->tenantId)->whereIn('mr.status', self::STATUSES);

        return $context->scopeBranch($query, 'mr.branch_id');
    }
}
