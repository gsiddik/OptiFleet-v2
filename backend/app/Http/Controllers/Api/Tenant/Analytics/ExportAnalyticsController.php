<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 6 Section 47 — CSV export of a domain's analytical table using
 * the exact same filters and data-scope restriction as the matching
 * /analytics/{domain} endpoint (Section 47: "same filters and
 * data-scope restrictions as UI/API"). Streams the response
 * (Section 47: "do not load unbounded datasets into memory") and is
 * capped at config('analytics.export.max_rows'). No XLSX writer library
 * is present in this project's dependencies (Section 47 makes XLSX
 * conditional on one already existing cleanly) — CSV only.
 */
class ExportAnalyticsController extends Controller
{
    /** domain slug => [collection, dimension field, DataScopeService scope type|null] */
    private const DOMAINS = [
        'fleet' => ['daily_fleet_snapshots', 'branch_id', 'branch'],
        'maintenance' => ['daily_maintenance_metrics', 'branch_id', 'branch'],
        'work-orders' => ['daily_work_order_metrics', 'workshop_id', 'workshop'],
        'breakdowns' => ['daily_breakdown_metrics', 'branch_id', 'branch'],
        'downtime' => ['daily_downtime_metrics', 'vehicle_id', null],
        'workshops' => ['daily_workshop_metrics', 'workshop_id', 'workshop'],
        'mechanics' => ['daily_mechanic_metrics', 'mechanic_id', null],
        'inventory' => ['daily_inventory_metrics', 'warehouse_id', 'warehouse'],
        'procurement' => ['daily_procurement_metrics', 'branch_id', 'branch'],
        'vendors' => ['daily_vendor_metrics', 'vendor_id', null],
        'cost' => ['daily_cost_metrics', 'branch_id', 'branch'],
        'tires' => ['daily_tire_metrics', 'branch_id', 'branch'],
        'components' => ['daily_component_failure_metrics', 'component_group_id', null],
        'warranty' => ['daily_warranty_metrics', 'branch_id', 'branch'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly DataScopeService $scope,
        private readonly AuditService $audit,
    ) {}

    public function export(Request $request, string $domain): StreamedResponse
    {
        abort_unless(isset(self::DOMAINS[$domain]), 404, 'Unknown analytics domain.');
        [$collection, $field, $scopeType] = self::DOMAINS[$domain];

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'dimension_value' => 'nullable|string|max:100']);

        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $to = $request->string('to')->value() ? CarbonImmutable::parse($request->string('to')->value()) : CarbonImmutable::now();
        $from = $request->string('from')->value() ? CarbonImmutable::parse($request->string('from')->value()) : $to->subDays(29);
        $dimensionValue = $request->string('dimension_value')->value() ?: null;

        if ($dimensionValue !== null && $scopeType) {
            $allowed = match ($scopeType) {
                'branch' => $this->scope->canAccessBranch($user, $tenantId, $dimensionValue),
                'workshop' => $this->scope->canAccessWorkshop($user, $tenantId, $dimensionValue),
                'warehouse' => $this->scope->canAccessWarehouse($user, $tenantId, $dimensionValue),
                default => true,
            };
            abort_unless($allowed, 403, 'This dimension value is outside your assigned data scope.');
        }

        $query = DB::connection('mongodb')->table($collection)
            ->where('tenant_id', $tenantId)
            ->where('snapshot_date', '>=', $from->format('Y-m-d'))
            ->where('snapshot_date', '<=', $to->format('Y-m-d'));

        if ($dimensionValue !== null) {
            $query->where($field, $dimensionValue);
        } elseif ($scopeType) {
            $allowedIds = match ($scopeType) {
                'branch' => $this->scope->allowedBranchIds($user, $tenantId),
                'workshop' => $this->scope->allowedWorkshopIds($user, $tenantId),
                'warehouse' => $this->scope->allowedWarehouseIds($user, $tenantId),
                default => null,
            };
            if ($allowedIds !== null) {
                $query->whereIn($field, $allowedIds);
            }
        }

        $this->audit->log(
            resourceType: 'AnalyticsExport',
            resourceId: $domain,
            action: 'exported',
            oldValues: null,
            newValues: ['domain' => $domain, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'dimension_value' => $dimensionValue],
            tenantId: $tenantId,
        );

        $maxRows = (int) config('analytics.export.max_rows', 50000);
        $chunkSize = (int) config('analytics.export.chunk_size', 1000);

        return response()->streamDownload(function () use ($query, $maxRows, $chunkSize) {
            $out = fopen('php://output', 'w');
            $headerWritten = false;
            $rowCount = 0;

            $query->orderBy('snapshot_date')->chunk($chunkSize, function ($rows) use ($out, &$headerWritten, &$rowCount, $maxRows) {
                foreach ($rows as $row) {
                    $flat = $this->flatten((array) $row);
                    unset($flat['_id']);

                    if (! $headerWritten) {
                        fputcsv($out, array_keys($flat));
                        $headerWritten = true;
                    }
                    fputcsv($out, array_values($flat));
                    $rowCount++;

                    if ($rowCount >= $maxRows) {
                        return false;
                    }
                }
            });

            fclose($out);
        }, "{$domain}-analytics.csv", ['Content-Type' => 'text/csv']);
    }

    private function flatten(array $row, string $prefix = ''): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            $label = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value) && ! array_is_list($value)) {
                $flat += $this->flatten($value, $label);
            } elseif (is_array($value)) {
                $flat[$label] = json_encode($value);
            } else {
                $flat[$label] = $value;
            }
        }

        return $flat;
    }
}
