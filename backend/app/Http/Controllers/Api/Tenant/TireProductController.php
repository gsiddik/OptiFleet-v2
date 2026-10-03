<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Services\TireInventoryService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tire Products: Products of Item Type TIRE (the same Product master as Products — no copy), with
 * the inventory of their physical tires. Tire List = this index; Tire Detail = show + inventory.
 */
class TireProductController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TireInventoryService $inventory,
    ) {}

    public function index(Request $request)
    {
        return $this->paginated($this->inventory->productList(
            $this->context->tenantId(), $this->context->user(),
            $request->string('search')->trim()->value() ?: null,
            min(max($request->integer('per_page', 20), 1), 100),
        ));
    }

    /** Product details (same payload as Product Detail) + inventory counts. */
    public function show(string $tireProduct)
    {
        $product = $this->tireProduct($tireProduct);

        return $this->ok($product->load(ProductController::detailRelations($product))->toArray() + [
            'inventory' => $this->inventory->summary($this->context->tenantId(), $this->context->user(), $product->id),
        ]);
    }

    /** One inventory table (NEW / INSTALLED / USED) of the product's physical tires, paginated. */
    public function inventory(Request $request, string $tireProduct)
    {
        $product = $this->tireProduct($tireProduct);
        $request->validate(['category' => ['required', Rule::in(TireInventoryService::CATEGORIES)]]);

        return $this->paginated($this->inventory->rows(
            $this->context->tenantId(), $this->context->user(), $product->id,
            $request->string('category')->value(),
            min(max($request->integer('per_page', 50), 1), 200),
        ));
    }

    /**
     * A Tire Product visible to the tenant (own or platform). Soft-deleted products stay readable
     * here so their physical tires and history remain reachable.
     */
    private function tireProduct(string $id): Product
    {
        $product = Product::query()->withTrashed()->find($id);
        abort_unless($product && $product->product_type === 'TIRE', 404);

        return $product;
    }
}
