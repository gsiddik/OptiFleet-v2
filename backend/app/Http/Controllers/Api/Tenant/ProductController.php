<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use App\Domain\ProductMaster\Services\ProductCompatibilityService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreProductRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductCompatibilityService $compatibility,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'uom']);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        foreach (['product_type', 'status', 'product_category_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreProductRequest $request)
    {
        $product = Product::query()->create($request->validated() + [
            'tenant_id' => $this->context->tenantId(), 'is_system' => false, 'status' => 'ACTIVE',
        ]);

        return $this->ok($product, 201);
    }

    public function show(Product $product)
    {
        $this->authorizeVisible($product);

        return $this->ok($product->load(['category', 'uom', 'componentGroups', 'compatibilities.componentGroup', 'compatibilities.vehicleCategory']));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'track_serial_number' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'reference_tread_depth_mm' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
        ]);
        $product->update($validated);

        return $this->ok($product->fresh());
    }

    public function syncComponentGroups(Request $request, Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $request->validate(['component_group_ids' => ['array'], 'component_group_ids.*' => ['uuid']]);
        $product->componentGroups()->sync($request->input('component_group_ids', []));

        return $this->ok($product->fresh('componentGroups'));
    }

    public function addCompatibility(Request $request, Product $product)
    {
        $this->authorizeVisible($product);

        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'vehicle_category_id' => ['nullable', 'uuid', 'exists:vehicle_categories,id'],
            'vehicle_brand' => ['nullable', 'string', 'max:100'],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $rule = ProductCompatibility::query()->create($validated + [
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
        ]);

        return $this->ok($rule, 201);
    }

    public function destroyCompatibility(Product $product, ProductCompatibility $compatibility)
    {
        $this->authorizeVisible($product);
        abort_unless($compatibility->product_id === $product->id, 404);

        $compatibility->delete();

        return $this->message('Compatibility rule removed.');
    }

    public function compatibleFor(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'component_group_id' => ['nullable', 'uuid'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);

        return $this->ok($this->compatibility->compatibleProducts($vehicle, $request->input('component_group_id')));
    }

    private function authorizeVisible(Product $product): void
    {
        abort_unless($product->tenant_id === null || $product->tenant_id === $this->context->tenantId(), 404);
    }
}
