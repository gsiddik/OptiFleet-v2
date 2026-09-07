<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\StockMovement;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Section 4/57: the movement ledger is append-only and can grow large —
 * always paginated, filterable by warehouse/product/type, never returned
 * in full.
 */
class StockMovementController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = StockMovement::query()->where('tenant_id', $tenantId)->with(['warehouse', 'product']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if ($warehouseId = $request->string('warehouse_id')->value()) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($productId = $request->string('product_id')->value()) {
            $query->where('product_id', $productId);
        }
        if ($movementType = $request->string('movement_type')->value()) {
            $query->where('movement_type', $movementType);
        }

        return $this->paginated($query->latest('occurred_at')->paginate($request->integer('per_page', 20)));
    }
}
