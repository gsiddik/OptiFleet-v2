<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Analytics\Kpi\KpiRegistry;
use App\Domain\Analytics\Services\DataFreshnessService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 41-43 — one controller serves every /analytics/{domain}
 * endpoint (fleet, maintenance, work-orders, ...); each concrete
 * subclass only declares which collection/dimension/KPIs it exposes.
 * This avoids 14 near-identical copies of the same filtering,
 * data-scope, and freshness logic (Section 67: don't duplicate
 * infrastructure).
 *
 * Data scope (Section 6/42): a dimension value the caller requests is
 * checked against DataScopeService before querying Mongo — a
 * branch/workshop/warehouse-scoped user can only see their own
 * dimension's documents, exactly like the transactional APIs. A caller
 * with unrestricted (TENANT) scope and no explicit dimension filter gets
 * the tenant-wide rollup document; a *restricted* caller with no
 * explicit filter gets the list of documents for their own allowed
 * dimension values instead of the rollup (which would otherwise leak an
 * aggregate over branches/workshops they cannot see).
 */
abstract class AnalyticsDomainController extends Controller
{
    public function __construct(
        protected readonly TenantContext $context,
        protected readonly DataScopeService $scope,
        protected readonly DataFreshnessService $freshness,
        protected readonly KpiRegistry $kpis,
    ) {}

    abstract protected function collection(): string;

    abstract protected function dimensionField(): string;

    /** 'branch'|'workshop'|'warehouse'|null — null means no DataScopeService coverage exists for this dimension (Section 4 limitation). */
    protected function scopeType(): ?string
    {
        return null;
    }

    /** @return string[] KPI codes surfaced alongside this domain's raw metrics. */
    protected function kpiCodes(): array
    {
        return [];
    }

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        [$from, $to] = $this->resolveRange($request);
        $requestedDimensionValue = $request->string('dimension_value')->value() ?: null;

        abort_unless(
            $this->canAccessDimensionValue($user, $tenantId, $requestedDimensionValue),
            403,
            'This dimension value is outside your assigned data scope.'
        );

        $dimensionValues = $this->resolveDimensionValues($user, $tenantId, $requestedDimensionValue);

        $documents = DB::connection('mongodb')->table($this->collection())
            ->where('tenant_id', $tenantId)
            ->where('snapshot_date', '>=', $from->format('Y-m-d'))
            ->where('snapshot_date', '<=', $to->format('Y-m-d'))
            ->whereIn($this->dimensionField(), $dimensionValues)
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values();

        $kpis = collect($this->kpiCodes())
            ->filter(fn ($code) => $this->kpis->has($code))
            ->map(fn ($code) => $this->kpis->get($code)->calculate($tenantId, $from, $to, $requestedDimensionValue))
            ->values();

        return $this->ok([
            'metrics' => $documents,
            'kpis' => $kpis,
            'freshness' => $this->freshness->forTenant($tenantId),
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ]);
    }

    protected function resolveRange(Request $request): array
    {
        $to = $request->string('to')->value()
            ? CarbonImmutable::parse($request->string('to')->value())
            : CarbonImmutable::now();
        $from = $request->string('from')->value()
            ? CarbonImmutable::parse($request->string('from')->value())
            : $to->subDays(29);

        return [$from, $to];
    }

    private function canAccessDimensionValue($user, string $tenantId, ?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return match ($this->scopeType()) {
            'branch' => $this->scope->canAccessBranch($user, $tenantId, $value),
            'workshop' => $this->scope->canAccessWorkshop($user, $tenantId, $value),
            'warehouse' => $this->scope->canAccessWarehouse($user, $tenantId, $value),
            default => true,
        };
    }

    /** @return array<string|null> */
    private function resolveDimensionValues($user, string $tenantId, ?string $requestedValue): array
    {
        if ($requestedValue !== null) {
            return [$requestedValue];
        }

        $allowed = match ($this->scopeType()) {
            'branch' => $this->scope->allowedBranchIds($user, $tenantId),
            'workshop' => $this->scope->allowedWorkshopIds($user, $tenantId),
            'warehouse' => $this->scope->allowedWarehouseIds($user, $tenantId),
            default => null,
        };

        // null = unrestricted (TENANT scope, or no DataScopeService coverage for this dimension) -> tenant rollup document.
        return $allowed === null ? [null] : $allowed;
    }
}
