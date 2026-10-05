<?php

namespace App\Domain\History\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\QualityControl\Models\QcInspection;
use App\Domain\VehicleRelease\Models\VehicleRelease;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Section 40: a queryable maintenance timeline built by tagging and merging each domain's own
 * existing records at read time — deliberately not a separate history table duplicating
 * transaction data. One query (a UNION of the sources, joined to the vehicle) serves both uses:
 *
 *  - Maintenance History (global): every vehicle the user may see — the caller passes the user's
 *    allowed branch ids (null = the whole tenant) — server-side paginated, newest first;
 *  - Vehicle History: one selected vehicle (vehicle_id filter), access checked by the caller.
 *
 * Vehicle and branch identity come from the same join, so a page never triggers N+1 queries.
 */
class HistoryService
{
    public const TYPES = ['INSPECTION', 'MAINTENANCE_REQUEST', 'BREAKDOWN', 'WORK_ORDER', 'QC', 'VEHICLE_RELEASE'];

    /**
     * @param  array{vehicle_id?: ?string, branch_id?: ?string, type?: ?string, date_from?: ?string, date_to?: ?string, search?: ?string}  $filters
     * @param  array<int, string>|null  $allowedBranchIds  null = every branch of the tenant
     */
    public function query(string $tenantId, array $filters = [], ?array $allowedBranchIds = null): Builder
    {
        $query = DB::query()->fromSub($this->events($tenantId), 'h')
            ->join('vehicles as v', 'v.id', '=', 'h.vehicle_id')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->where('v.tenant_id', $tenantId)->whereNull('v.deleted_at')
            ->select(['h.*', 'v.registration_number', 'v.brand', 'v.model', 'v.branch_id', 'b.name as branch_name']);

        if ($allowedBranchIds !== null) {
            $query->whereIn('v.branch_id', $allowedBranchIds);
        }
        foreach (['vehicle_id' => 'h.vehicle_id', 'branch_id' => 'v.branch_id', 'type' => 'h.type'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['date_from'])) {
            $query->where('h.at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('h.at', '<', now()->parse($filters['date_to'])->addDay()->startOfDay());
        }
        if (! empty($filters['search'])) {
            $query->where('v.registration_number', 'ilike', '%'.$filters['search'].'%');
        }

        return $query->orderByDesc('h.at')->orderByDesc('h.id');
    }

    /** Maintenance History: one page of events across the accessible vehicles. */
    public function paginate(string $tenantId, array $filters, ?array $allowedBranchIds, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->query($tenantId, $filters, $allowedBranchIds)->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row)));

        return $page;
    }

    /** Vehicle History: the full timeline of one vehicle, newest first. */
    public function forVehicle(string $tenantId, string $vehicleId): Collection
    {
        return $this->query($tenantId, ['vehicle_id' => $vehicleId])->get()->map(fn ($row) => $this->present($row))->values();
    }

    /** The normalised sources: type, id, at, vehicle_id, work_order_id, ref, status, detail. */
    private function events(string $tenantId): Builder
    {
        $null = fn (string $type) => DB::raw("CAST(NULL AS {$type})");

        $inspections = Inspection::query()->where('inspections.tenant_id', $tenantId)->select([
            DB::raw("'INSPECTION' as type"), 'inspections.id', DB::raw('COALESCE(inspections.submitted_at, inspections.created_at) as at'), 'inspections.vehicle_id',
            DB::raw('CAST(NULL AS uuid) as work_order_id'), DB::raw('CAST(NULL AS varchar) as ref'), DB::raw('CAST(inspections.status AS varchar) as status'), DB::raw('CAST(inspections.inspection_type AS varchar) as detail'),
        ])->toBase();
        $requests = MaintenanceRequest::query()->where('maintenance_requests.tenant_id', $tenantId)->select([
            DB::raw("'MAINTENANCE_REQUEST'"), 'maintenance_requests.id', 'maintenance_requests.created_at', 'maintenance_requests.vehicle_id',
            $null('uuid'), DB::raw('CAST(maintenance_requests.request_number AS varchar)'), DB::raw('CAST(maintenance_requests.status AS varchar)'), $null('varchar'),
        ])->toBase();
        $breakdowns = Breakdown::query()->where('breakdowns.tenant_id', $tenantId)->select([
            DB::raw("'BREAKDOWN'"), 'breakdowns.id', 'breakdowns.reported_at', 'breakdowns.vehicle_id',
            $null('uuid'), $null('varchar'), DB::raw('CAST(breakdowns.status AS varchar)'), DB::raw('CAST(breakdowns.severity AS varchar)'),
        ])->toBase();
        $workOrders = WorkOrder::query()->where('work_orders.tenant_id', $tenantId)->select([
            DB::raw("'WORK_ORDER'"), 'work_orders.id', 'work_orders.created_at', 'work_orders.vehicle_id',
            'work_orders.id', DB::raw('CAST(work_orders.wo_number AS varchar)'), DB::raw('CAST(work_orders.status AS varchar)'), DB::raw('CAST(work_orders.maintenance_type AS varchar)'),
        ])->toBase();
        $qc = QcInspection::query()->join('work_orders as qwo', 'qwo.id', '=', 'qc_inspections.work_order_id')
            ->where('qc_inspections.tenant_id', $tenantId)->select([
                DB::raw("'QC'"), 'qc_inspections.id', DB::raw('COALESCE(qc_inspections.completed_at, qc_inspections.created_at)'), 'qwo.vehicle_id',
                'qc_inspections.work_order_id', $null('varchar'), DB::raw('CAST(qc_inspections.status AS varchar)'), $null('varchar'),
            ])->toBase();
        $releases = VehicleRelease::query()->where('vehicle_releases.tenant_id', $tenantId)->select([
            DB::raw("'VEHICLE_RELEASE'"), 'vehicle_releases.id', 'vehicle_releases.released_at', 'vehicle_releases.vehicle_id',
            'vehicle_releases.work_order_id', $null('varchar'), $null('varchar'), $null('varchar'),
        ])->toBase();

        return $inspections->unionAll($requests)->unionAll($breakdowns)->unionAll($workOrders)->unionAll($qc)->unionAll($releases);
    }

    /** One timeline event as the API returns it (the summary texts are unchanged). */
    private function present(object $row): array
    {
        $summary = match ($row->type) {
            'INSPECTION' => "{$row->detail} inspection — {$row->status}",
            'MAINTENANCE_REQUEST' => "Request {$row->ref} — {$row->status}",
            'BREAKDOWN' => "Breakdown ({$row->detail}) — {$row->status}",
            'WORK_ORDER' => "Work Order {$row->ref} ({$row->detail}) — {$row->status}",
            'QC' => "QC inspection — {$row->status}",
            'VEHICLE_RELEASE' => 'Vehicle released',
            default => $row->type,
        };

        return array_filter([
            'type' => $row->type,
            'id' => $row->id,
            'at' => $row->at ? Carbon::parse($row->at)->toJSON() : null,
            'summary' => $summary,
            'status' => $row->status,
            'reference' => $row->ref,
            'work_order_id' => $row->type === 'WORK_ORDER' ? null : $row->work_order_id,
            'vehicle_id' => $row->vehicle_id,
            'vehicle' => [
                'id' => $row->vehicle_id, 'registration_number' => $row->registration_number, 'brand' => $row->brand, 'model' => $row->model,
                'branch_id' => $row->branch_id, 'branch_name' => $row->branch_name,
            ],
        ], fn ($v) => $v !== null);
    }
}
