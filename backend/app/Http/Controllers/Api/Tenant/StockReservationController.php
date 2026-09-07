<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\StockReservationService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class StockReservationController extends Controller
{
    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = StockReservation::query()->where('tenant_id', $tenantId)->with(['warehouse', 'workOrder.vehicle', 'items.product']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($workOrderId = $request->string('work_order_id')->value()) {
            $query->where('work_order_id', $workOrderId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(StockReservation $stockReservation)
    {
        $this->authorizeScope($stockReservation);

        return $this->ok($stockReservation->load(['warehouse', 'workOrder.vehicle', 'items.product']));
    }

    public function cancel(StockReservation $stockReservation)
    {
        $this->authorizeScope($stockReservation);

        return $this->ok($this->reservations->cancel($stockReservation, $this->context->user()->id));
    }

    private function authorizeScope(StockReservation $reservation): void
    {
        abort_unless($reservation->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $reservation->warehouse_id),
            403,
            'This warehouse is outside your assigned data scope.'
        );
    }
}
