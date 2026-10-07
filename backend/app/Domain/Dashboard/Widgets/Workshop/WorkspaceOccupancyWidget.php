<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** WS-05 Workspace Occupancy — current workspace (bay) status per workshop; INACTIVE bays are left out. */
class WorkspaceOccupancyWidget extends Widget
{
    public const STATUSES = ['AVAILABLE', 'RESERVED', 'OCCUPIED', 'BLOCKED', 'UNDER_MAINTENANCE'];

    public function id(): string
    {
        return 'WS-05';
    }

    public function modules(): array
    {
        return ['WORKSHOP'];
    }

    public function permissions(): array
    {
        return ['workspace.view'];
    }

    public function filters(): array
    {
        return ['branch', 'workshop'];
    }

    public function compute(DashboardContext $context): array
    {
        $query = DB::table('workspaces as w')->join('workshops as ws', 'ws.id', '=', 'w.workshop_id')
            ->where('w.tenant_id', $context->tenantId)->whereNull('w.deleted_at')->whereNull('ws.deleted_at')
            ->whereIn('w.status', self::STATUSES);
        $context->scopeWorkshop($query, 'w.workshop_id');
        $rows = $query->selectRaw('w.workshop_id, ws.name as workshop_name, w.status, count(*) as c')
            ->groupBy('w.workshop_id', 'ws.name', 'w.status')->get();

        $workshops = [];
        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $workshops[$row->workshop_id] ??= ['workshop_id' => $row->workshop_id, 'workshop_name' => $row->workshop_name, 'total' => 0,
                'by_status' => array_fill_keys(self::STATUSES, 0)];
            $workshops[$row->workshop_id]['by_status'][$row->status] = (int) $row->c;
            $workshops[$row->workshop_id]['total'] += (int) $row->c;
            $totals[$row->status] += (int) $row->c;
        }
        $workshops = array_values($workshops);
        usort($workshops, fn ($a, $b) => strcmp((string) $a['workshop_name'], (string) $b['workshop_name']));

        return ['data' => ['total' => array_sum($totals), 'by_status' => $totals, 'workshops' => $workshops]];
    }
}
