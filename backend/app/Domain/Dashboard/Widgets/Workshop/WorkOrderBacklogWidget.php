<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;

/** WS-01 Work Order Backlog — open Work Orders by status (+ DRAFT shown separately). */
class WorkOrderBacklogWidget extends WorkOrderWidget
{
    public function id(): string
    {
        return 'WS-01';
    }

    public function compute(DashboardContext $context): array
    {
        $counts = self::countsByKey($this->workOrders($context)->whereIn('wo.status', self::OPEN_STATUSES), 'wo.status', self::OPEN_STATUSES);
        $drafts = $this->workOrders($context)->where('wo.status', 'DRAFT')->count();

        return ['data' => ['total' => array_sum($counts), 'by_status' => $counts, 'draft' => $drafts]];
    }

    public function detailRules(): ?array
    {
        return ['status' => ['nullable', 'in:DRAFT,'.implode(',', self::OPEN_STATUSES)]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->withListColumns($this->workOrders($context))
            ->when($params['status'] ?? null, fn ($q, $s) => $q->where('wo.status', $s), fn ($q) => $q->whereIn('wo.status', self::OPEN_STATUSES))
            ->orderBy('wo.created_at');

        return $this->paginate($query, $params, fn ($r) => $this->presentRow($r, $context));
    }
}
