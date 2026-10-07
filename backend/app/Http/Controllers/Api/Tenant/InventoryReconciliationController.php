<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\StockReconciliationAdjustment as Adjustment;
use App\Domain\Inventory\Services\SerializedStockReconciliationService as Report;
use App\Domain\Inventory\Services\StockReconciliationAdjustmentService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reconciliation of old serialized installations: read-only report with opname evidence (inventory_reconcile.view),
 * proposal / apply (inventory_reconcile.manage) and approval (inventory_reconcile.approve). Warehouse data scope is
 * enforced on every list and action; tenant comes from the token, never from input.
 */
class InventoryReconciliationController extends Controller
{
    public function __construct(
        private readonly Report $report,
        private readonly StockReconciliationAdjustmentService $adjustments,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function report(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $data = $request->validate([
            'category' => ['nullable', 'string', Rule::in([Report::UNDEDUCTED, Report::COVERED, Report::RESOLVED_BY_OPNAME, Report::AMBIGUOUS_NO_RECEIPT, Report::AMBIGUOUS_TIRE, Report::NOT_WAREHOUSE])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $allowed = $this->scope->allowedWarehouseIds($this->context->user(), $tenantId); // null = every warehouse
        $inScope = fn (?string $warehouseId) => $allowed === null || ($warehouseId !== null && in_array($warehouseId, $allowed, true));

        $tenant = $this->report->plan($tenantId)['tenants'][0] ?? ['summary' => [], 'candidates' => [], 'balance' => []];
        // The latest adjustment of each installation, so a case already proposed / approved / rejected is not offered again blindly.
        $latest = Adjustment::query()->where('tenant_id', $tenantId)->orderBy('proposed_at')->get(['id', 'installation_id', 'status'])->keyBy('installation_id');
        $candidates = collect($tenant['candidates'])->map(function (array $c) use ($inScope, $latest) {
            $c['adjustment'] = isset($latest[$c['installation_id']]) ? ['id' => $latest[$c['installation_id']]->id, 'status' => $latest[$c['installation_id']]->status] : null;
            if ($c['warehouse_evidence']) {
                $c['warehouse_evidence'] = array_values(array_filter($c['warehouse_evidence'], fn ($w) => $inScope($w['warehouse_id'])));
            }

            return $c;
        })->filter(fn (array $c) => $c['warehouse_id'] ? $inScope($c['warehouse_id']) : ($allowed === null || $c['warehouse_evidence'] !== []))->values();
        $summary = ['installations' => $candidates->count()];
        foreach ([Report::UNDEDUCTED, Report::COVERED, Report::RESOLVED_BY_OPNAME, Report::AMBIGUOUS_NO_RECEIPT, Report::AMBIGUOUS_TIRE, Report::NOT_WAREHOUSE] as $category) {
            $summary[$category] = $candidates->where('category', $category)->count();
        }
        $summary[Report::CORRECTED] = Adjustment::query()->where('tenant_id', $tenantId)->where('status', Adjustment::APPLIED)
            ->when($allowed !== null, fn ($q) => $q->whereIn('warehouse_id', $allowed))->count();

        $filtered = isset($data['category']) ? $candidates->where('category', $data['category'])->values() : $candidates;
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);

        return response()->json([
            'data' => $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($filtered->count() / $perPage)), 'per_page' => $perPage, 'total' => $filtered->count()],
            'summary' => $summary,
            'balance' => array_values(array_filter($tenant['balance'], fn ($b) => $inScope($b['warehouse_id']))),
            'generated_at' => now()->toIso8601String(),
            'notes' => ['opname_proves' => 'PHYSICAL_QUANTITY_AT_THAT_TIME', 'tires_never_adjusted_automatically' => true],
        ]);
    }

    public function adjustments(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $data = $request->validate(['status' => ['nullable', 'string', Rule::in([Adjustment::PENDING, Adjustment::APPROVED, Adjustment::APPLIED, Adjustment::REJECTED, Adjustment::SUPERSEDED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Adjustment::query()->where('tenant_id', $tenantId)->orderByDesc('proposed_at');
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $this->paginated($query->paginate((int) ($data['per_page'] ?? 25)));
    }

    public function propose(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $data = $request->validate(['installation_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000']]);
        $candidate = $this->report->evaluate($tenantId, $data['installation_id']);
        $existing = Adjustment::query()->where('tenant_id', $tenantId)->where('installation_id', $data['installation_id'])->orderByDesc('proposed_at')->first();
        $warehouseId = $candidate['warehouse_id'] ?? $existing?->warehouse_id; // an already corrected / proposed installation is no candidate any more: the service explains why
        abort_if($warehouseId === null || ! $this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouseId), 404);

        return $this->ok($this->adjustments->propose($tenantId, $data['installation_id'], $data['reason'], $this->context->user()->id), 201);
    }

    public function approve(Request $request, string $adjustment)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->ok($this->adjustments->decide($this->context->tenantId(), $this->scoped($adjustment)->id, 'APPROVE', $this->context->user()->id, $data['note'] ?? null));
    }

    public function reject(Request $request, string $adjustment)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->ok($this->adjustments->decide($this->context->tenantId(), $this->scoped($adjustment)->id, 'REJECT', $this->context->user()->id, $data['note']));
    }

    public function apply(string $adjustment)
    {
        return $this->ok($this->adjustments->apply($this->context->tenantId(), $this->scoped($adjustment)->id, $this->context->user()->id));
    }

    private function scoped(string $id): Adjustment
    {
        $tenantId = $this->context->tenantId();
        $adjustment = Adjustment::query()->where('tenant_id', $tenantId)->findOrFail($id);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $adjustment->warehouse_id), 404);

        return $adjustment;
    }
}
