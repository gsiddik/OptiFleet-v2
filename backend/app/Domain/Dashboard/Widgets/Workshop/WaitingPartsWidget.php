<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WS-06 Work Orders Waiting for Parts — Work Orders in WAITING_PART with their part requests that
 * are still REQUESTED/APPROVED (not issued). The waiting time is measured from the oldest pending
 * part request (a recorded timestamp), not from a status change that has no dedicated history.
 */
class WaitingPartsWidget extends WorkOrderWidget
{
    public const PENDING_REQUEST_STATUSES = ['REQUESTED', 'APPROVED'];

    public function id(): string
    {
        return 'WS-06';
    }

    public function compute(DashboardContext $context): array
    {
        $count = $this->waiting($context)->count();
        $items = $this->rows($context)->limit(8)->get()->map(fn ($r) => $this->present($r, $context))->all();

        return ['data' => ['count' => $count, 'items' => $items]];
    }

    public function detailRules(): ?array
    {
        return [];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        return $this->paginate($this->rows($context), $params, fn ($r) => $this->present($r, $context));
    }

    private function waiting(DashboardContext $context): Builder
    {
        return $this->workOrders($context)->where('wo.status', 'WAITING_PART');
    }

    private function rows(DashboardContext $context): Builder
    {
        $pending = DB::table('work_order_part_requests as pr')
            ->leftJoin('work_order_part_request_items as pri', 'pri.part_request_id', '=', 'pr.id')
            ->where('pr.tenant_id', $context->tenantId)->whereIn('pr.status', self::PENDING_REQUEST_STATUSES)
            ->groupBy('pr.work_order_id')
            ->selectRaw('pr.work_order_id, min(pr.requested_at) as oldest_requested_at, count(distinct pr.id) as pending_requests, count(pri.id) as pending_items');

        return $this->withListColumns($this->waiting($context))
            ->leftJoinSub($pending, 'pend', 'pend.work_order_id', '=', 'wo.id')
            ->addSelect(['pend.oldest_requested_at', 'pend.pending_requests', 'pend.pending_items'])
            ->orderByRaw('pend.oldest_requested_at asc nulls last')->orderBy('wo.created_at');
    }

    private function present(object $r, DashboardContext $context): array
    {
        return $this->presentRow($r, $context) + [
            'oldest_requested_at' => self::isoUtc($r->oldest_requested_at),
            'waiting_days' => $this->ageDays($r->oldest_requested_at, $context),
            'pending_requests' => (int) ($r->pending_requests ?? 0),
            'pending_items' => (int) ($r->pending_items ?? 0),
        ];
    }
}
