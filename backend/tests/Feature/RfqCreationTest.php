<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * New RFQ page: destination warehouse, multi-product selection with per-line quantity, product
 * search / Product Category / Vehicle Model filters, saved as DRAFT. The backend re-validates
 * every line independently of the page.
 */
class RfqCreationTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'RFQ-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        [, $token] = $this->makeTenantUser($tenant, ['rfq.view', 'rfq.manage', 'product.view']);

        return [$tenant, $warehouse, $this->authHeaders($token)];
    }

    public function test_multi_product_rfq_is_saved_as_draft_with_each_quantity(): void
    {
        [$tenant, $warehouse, $headers] = $this->setUpTenant();
        $pads = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        $oil = $this->makeProduct($tenant, null, $this->makeUom(['measure_type' => 'CAPACITY']), ['name' => 'Engine Oil', 'product_type' => 'CONSUMABLE']);
        $this->makeProduct($tenant, null, null, ['name' => 'Not Selected']);

        $response = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $pads->id, 'quantity' => 4], ['product_id' => $oil->id, 'quantity' => 12.5]],
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'DRAFT');

        $rfq = Rfq::query()->with('items')->findOrFail($response->json('data.id'));
        $this->assertSame($warehouse->id, $rfq->warehouse_id);
        $this->assertEqualsCanonicalizing([$pads->id, $oil->id], $rfq->items->pluck('product_id')->all());
        $this->assertSame(['4.0000', '12.5000'], [$rfq->items->firstWhere('product_id', $pads->id)->quantity, $rfq->items->firstWhere('product_id', $oil->id)->quantity]);
    }

    public function test_lines_are_validated_independently_of_the_page(): void
    {
        [$tenant, $warehouse, $headers] = $this->setUpTenant();
        $pads = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        $inactive = $this->makeProduct($tenant, null, null, ['status' => 'INACTIVE']);
        $foreign = $this->makeProduct($this->makeTenant(['code' => 'RFQX-'.Str::random(4)]));
        $post = fn (array $items, ?string $warehouseId = null) => $this->postJson('/api/v1/app/rfqs', ['warehouse_id' => $warehouseId ?? $warehouse->id, 'items' => $items], $headers);

        $post([])->assertStatus(422)->assertJsonValidationErrors('items');
        $post([['product_id' => $pads->id, 'quantity' => 0]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $post([['product_id' => $pads->id]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $post([['product_id' => $pads->id, 'quantity' => 1.5]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $post([['product_id' => $pads->id, 'quantity' => 1], ['product_id' => $pads->id, 'quantity' => 2]])->assertStatus(422)->assertJsonValidationErrors('items.1.product_id');
        $post([['product_id' => $inactive->id, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
        $post([['product_id' => $foreign->id, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
        $this->assertSame(0, Rfq::query()->count());
    }

    public function test_warehouse_must_be_the_tenants_and_inside_the_users_scope(): void
    {
        [$tenant, $warehouse, $headers] = $this->setUpTenant();
        $pads = $this->makeProduct($tenant);
        $other = $this->makeTenant(['code' => 'RFQW-'.Str::random(4)]);
        $foreignWarehouse = $this->makeWarehouse($other, $this->makeBranch($other));
        $this->postJson('/api/v1/app/rfqs', ['warehouse_id' => $foreignWarehouse->id, 'items' => [['product_id' => $pads->id, 'quantity' => 1]]], $headers)->assertNotFound();
        $this->postJson('/api/v1/app/rfqs', ['items' => [['product_id' => $pads->id, 'quantity' => 1]]], $headers)->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

        $scopedWarehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        [, $scopedToken] = $this->makeTenantUser($tenant, ['rfq.manage'], ['WAREHOUSE' => $scopedWarehouse->id]);
        $this->postJson('/api/v1/app/rfqs', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $pads->id, 'quantity' => 1]]], $this->authHeaders($scopedToken))->assertForbidden();

        [, $viewer] = $this->makeTenantUser($tenant, ['rfq.view']);
        $this->postJson('/api/v1/app/rfqs', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $pads->id, 'quantity' => 1]]], $this->authHeaders($viewer))->assertForbidden();
    }

    public function test_product_picker_searches_by_name_and_filters_by_category_and_vehicle_model(): void
    {
        [$tenant, , $headers] = $this->setUpTenant();
        $brakes = $this->makeProductCategory(['name' => 'Brakes']);
        $brakePads = $this->makeProductCategory(['name' => 'Brake Pads', 'parent_id' => $brakes->id]);
        $filters = $this->makeProductCategory(['name' => 'Filters']);
        $pads = $this->makeProduct($tenant, $brakePads, null, ['name' => 'Brake Pad Front']);
        $disc = $this->makeProduct($tenant, $brakes, null, ['name' => 'Brake Disc']);
        $filter = $this->makeProduct($tenant, $filters, null, ['name' => 'Oil Filter']);
        $universal = $this->makeProduct($tenant, $filters, null, ['name' => 'Air Filter']);

        $ranger = $this->vehicleFit('Hino', 'Ranger FG');
        $dutro = $this->vehicleFit('Hino', 'Dutro');
        $avanza = $this->vehicleFit('Toyota', 'Avanza');
        $fit = fn ($product, array $pair, bool $anyModel = false) => ProductCompatibility::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'vehicle_brand_id' => $pair['vehicle_brand_id'],
            'vehicle_model_id' => $anyModel ? null : $pair['vehicle_model_id'], 'vehicle_brand' => 'x', 'vehicle_model' => $anyModel ? null : 'y',
        ]);
        $fit($pads, $ranger);
        $fit($disc, $dutro);
        $fit($filter, $ranger, anyModel: true); // any Hino model
        $fit($universal, $avanza);

        $names = fn (string $query) => collect($this->getJson("/api/v1/app/products?status=ACTIVE&per_page=50&{$query}", $headers)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Brake Disc', 'Brake Pad Front'], $names('search=brake'));
        $this->assertSame(['Brake Disc', 'Brake Pad Front'], $names("category_id={$brakes->id}"), 'A category includes its subcategories.');
        $this->assertSame(['Brake Pad Front'], $names("category_id={$brakePads->id}"));
        $this->assertSame(['Brake Pad Front', 'Oil Filter'], $names("vehicle_model_id={$ranger['vehicle_model_id']}"), 'Model rules plus brand-wide rules.');
        $this->assertSame(['Air Filter'], $names("vehicle_model_id={$avanza['vehicle_model_id']}"));
        $this->assertSame(['Oil Filter'], $names("category_id={$filters->id}&vehicle_model_id={$ranger['vehicle_model_id']}"));
        $this->assertSame([], $names('vehicle_model_id='.VehicleModel::query()->withoutGlobalScopes()->where('name', 'Dutro')->value('id').'&search=pad'));
        $this->getJson('/api/v1/app/products?vehicle_model_id=not-a-uuid', $headers)->assertStatus(422);
    }
}
