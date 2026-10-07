<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\StockValuationReview;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\StockValuationService;
use App\Domain\Inventory\Support\ValuationStatus;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Valuation status of warehouse balances: read (inventory_valuation.view) and review (inventory_valuation.verify). */
class InventoryValuationController extends Controller
{
    public function __construct(
        private readonly StockValuationService $valuation,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in([...ValuationStatus::ALL])],
            'warehouse_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = WarehouseStock::query()->where('tenant_id', $tenantId)->where('quantity_on_hand', '>', 0)->with(['warehouse:id,name', 'product:id,name,sku,product_type']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');
        if (! empty($data['status'])) {
            $query->where('valuation_status', $data['status']);
        }
        if (! empty($data['warehouse_id'])) {
            $query->where('warehouse_id', $data['warehouse_id']);
        }
        if (! empty($data['search'])) {
            $s = $data['search'];
            $query->whereHas('product', fn ($q) => $q->where('name', 'ilike', "%{$s}%")->orWhere('sku', 'ilike', "%{$s}%"));
        }

        return $this->paginated($query->orderBy('warehouse_id')->orderBy('product_id')->paginate((int) ($data['per_page'] ?? 20)), fn (WarehouseStock $s) => [
            'id' => $s->id, 'warehouse' => $s->warehouse, 'product' => $s->product,
            'quantity_on_hand' => $s->quantity_on_hand, 'average_unit_cost' => $s->average_unit_cost,
            'valuation_status' => $s->valuation_status ?? ValuationStatus::UNVERIFIED, 'valuation_basis' => $s->valuation_basis,
        ]);
    }

    public function show(string $warehouseStock)
    {
        $tenantId = $this->context->tenantId();
        $stock = WarehouseStock::query()->where('tenant_id', $tenantId)->with(['warehouse:id,name', 'product:id,name,sku,product_type'])->findOrFail($warehouseStock);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $stock->warehouse_id), 404);

        return $this->ok([
            'id' => $stock->id, 'warehouse' => $stock->warehouse, 'product' => $stock->product,
            'quantity_on_hand' => $stock->quantity_on_hand, 'average_unit_cost' => $stock->average_unit_cost,
            'valuation_status' => $stock->valuation_status ?? ValuationStatus::UNVERIFIED, 'valuation_basis' => $stock->valuation_basis,
            'sources' => $this->valuation->sources($stock),
            'reviews' => StockValuationReview::query()->where('tenant_id', $tenantId)->where('warehouse_stock_id', $stock->id)->orderByDesc('reviewed_at')->limit(50)->get(),
            'review_bases' => ValuationStatus::REVIEW_BASES,
        ]);
    }

    public function review(Request $request, string $warehouseStock)
    {
        $tenantId = $this->context->tenantId();
        $stock = WarehouseStock::query()->where('tenant_id', $tenantId)->findOrFail($warehouseStock);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $stock->warehouse_id), 404);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(ValuationStatus::REVIEW_BASES))],
            'basis' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'acknowledge_mixed_sources' => ['nullable', 'boolean'],
        ]);
        $review = $this->valuation->review($tenantId, $stock->id, $data['status'], $data['basis'], $data['reason'], $data['evidence_reference'] ?? null, (bool) ($data['acknowledge_mixed_sources'] ?? false), $this->context->user()->id);

        return $this->ok($review, 201);
    }
}
