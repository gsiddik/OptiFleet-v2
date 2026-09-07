<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Organization\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWarehouseRequest;
use App\Http\Requests\Tenant\UpdateWarehouseRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Warehouse::query();
        $this->scope->applyWarehouseScope($query, $user, $tenantId);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($type = $request->string('warehouse_type')->value()) {
            $query->where('warehouse_type', $type);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreWarehouseRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $this->capacity->assertCanCreate($tenantId, 'warehouse');

        $warehouse = Warehouse::query()->create($request->validated() + ['status' => $request->input('status', 'DRAFT')]);

        return $this->ok($warehouse, 201);
    }

    public function show(Warehouse $warehouse)
    {
        $this->authorizeScope($warehouse);

        return $this->ok($warehouse);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        $this->authorizeScope($warehouse);

        $warehouse->update($request->validated());

        return $this->ok($warehouse);
    }

    public function activate(Warehouse $warehouse)
    {
        $this->authorizeScope($warehouse);
        $warehouse->update(['status' => 'ACTIVE']);

        return $this->ok($warehouse);
    }

    public function deactivate(Warehouse $warehouse)
    {
        $this->authorizeScope($warehouse);
        $warehouse->update(['status' => 'INACTIVE']);

        return $this->ok($warehouse);
    }

    private function authorizeScope(Warehouse $warehouse): void
    {
        abort_unless($warehouse->tenant_id === $this->context->tenantId(), 404);

        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $warehouse->id),
            403,
            'This warehouse is outside your assigned data scope.'
        );
    }
}
