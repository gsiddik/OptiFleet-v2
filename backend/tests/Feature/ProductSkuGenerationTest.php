<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Services\ProductSkuService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner-approved: Product SKU is server-generated as
 * [Item Type code]-[Component Group abbreviation]-[sequence] through the
 * configurable `product_sku` numbering format, issued once and never
 * regenerated; Component Group + Category are mandatory for
 * Sparepart/Consumable/Tire/Rim, Subcategory optional.
 */
class ProductSkuGenerationTest extends TestCase
{
    private function tenantWithUser(): array
    {
        $tenant = $this->makeTenant(['code' => 'SKU-'.Str::upper(Str::random(4))]);
        $this->grantModule($tenant, 'CORE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update', 'warehouse.view', 'component_group.view', 'component_group.create', 'component_group.update']);

        return [$tenant, $this->authHeaders($token)];
    }

    private function classificationFor(string $abbreviation): array
    {
        $group = ComponentGroup::query()->whereNull('tenant_id')->where('abbreviation', $abbreviation)->first()
            ?? $this->makeComponentGroup(['abbreviation' => $abbreviation, 'name' => "Group {$abbreviation}"]);
        $category = ComponentCategory::query()->firstOrCreate(
            ['tenant_id' => null, 'component_group_id' => $group->id, 'code' => 'GENERAL'],
            ['name' => 'General', 'is_system' => true, 'status' => 'ACTIVE']
        );

        return ['component_group_id' => $group->id, 'component_category_id' => $category->id];
    }

    private function sparepart($tenant, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Brake Pad Front',
            'product_category_id' => $this->makeProductCategory()->id,
            'product_type' => 'SPARE_PART',
            'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->makeWarehouseBin($tenant)->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-'.Str::random(5), 'part_type' => 'GENUINE', 'compatibilities' => [$this->vehicleFit('Hino', 'Ranger')]],
        ], $overrides);
    }

    private function issue(string $tenantId, string $type, ?string $groupId): string
    {
        return DB::transaction(fn () => app(ProductSkuService::class)->generate($tenantId, $type, $groupId));
    }

    public function test_sku_is_generated_from_item_type_and_group_abbreviation_with_a_sequence_per_prefix(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();

        $first = $this->postJson('/api/v1/app/products', $this->sparepart($tenant, ['sku' => 'CLIENT-SKU'] + $this->classificationFor('BRK')), $headers)->assertCreated();
        $second = $this->postJson('/api/v1/app/products', $this->sparepart($tenant, $this->classificationFor('BRK')), $headers)->assertCreated();
        $this->assertSame('SPR-BRK-000001', $first->json('data.sku'), 'A client-supplied SKU is ignored.');
        $this->assertSame('SPR-BRK-000002', $second->json('data.sku'));
        $this->assertStringStartsWith('ITM/', $first->json('data.code'), 'Item Code numbering is unchanged.');

        $this->assertSame('SPR-ENG-000001', $this->issue($tenant->id, 'SPARE_PART', $this->classificationFor('ENG')['component_group_id']));
        $this->assertSame('CON-LUB-000001', $this->issue($tenant->id, 'CONSUMABLE', $this->classificationFor('LUB')['component_group_id']));
        $this->assertSame('TIR-WTY-000001', $this->issue($tenant->id, 'TIRE', $this->classificationFor('WTY')['component_group_id']));
        $this->assertSame('RIM-WTY-000001', $this->issue($tenant->id, 'RIM', $this->classificationFor('WTY')['component_group_id']));
        $this->assertSame('SPR-BRK-000003', $this->issue($tenant->id, 'SPARE_PART', $this->classificationFor('BRK')['component_group_id']));
    }

    public function test_unclassified_tools_and_equipment_get_a_two_segment_sku(): void
    {
        [$tenant] = $this->tenantWithUser();

        $this->assertSame('TOL-000001', $this->issue($tenant->id, 'TOOL', null));
        $this->assertSame('EQP-000001', $this->issue($tenant->id, 'EQUIPMENT', null));
        $this->assertSame('TOL-000002', $this->issue($tenant->id, 'TOOL', null));
        $this->assertSame('TOL-HYD-000001', $this->issue($tenant->id, 'TOOL', $this->classificationFor('HYD')['component_group_id']));
    }

    public function test_sequences_are_per_tenant_and_skip_numbers_taken_by_legacy_manual_skus(): void
    {
        [$tenantA] = $this->tenantWithUser();
        [$tenantB] = $this->tenantWithUser();
        $brake = $this->classificationFor('BRK')['component_group_id'];
        $this->makeProduct($tenantA, null, null, ['sku' => 'SPR-BRK-000001']);

        $this->assertSame('SPR-BRK-000002', $this->issue($tenantA->id, 'SPARE_PART', $brake));
        $this->assertSame('SPR-BRK-000001', $this->issue($tenantB->id, 'SPARE_PART', $brake));
    }

    public function test_category_is_mandatory_for_stocked_item_types_and_subcategory_is_optional(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();

        $this->postJson('/api/v1/app/products', $this->sparepart($tenant), $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['component_group_id', 'component_category_id']);
        $this->postJson('/api/v1/app/products', $this->sparepart($tenant, ['component_group_id' => $this->classificationFor('BRK')['component_group_id']]), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_category_id');
        // Group + Category, no Subcategory: accepted.
        $this->postJson('/api/v1/app/products', $this->sparepart($tenant, $this->classificationFor('BRK')), $headers)
            ->assertCreated()->assertJsonPath('data.component_subcategory_id', null);
    }

    public function test_group_without_abbreviation_cannot_issue_skus(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $legacy = $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => null]);
        $category = ComponentCategory::query()->create(['tenant_id' => $tenant->id, 'component_group_id' => $legacy->id, 'code' => 'GENERAL', 'name' => 'General', 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->sparepart($tenant, ['component_group_id' => $legacy->id, 'component_category_id' => $category->id]), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_group_id');
        $this->assertSame(0, Product::query()->count());
    }

    public function test_issued_sku_is_immutable_and_locks_the_abbreviation(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $tenantGroup = $this->postJson('/api/v1/app/component-groups', ['code' => 'CG-BRAKING', 'name' => 'Brake System', 'abbreviation' => 'BRA'], $headers)->assertCreated()->json('data.id');
        $category = ComponentCategory::query()->create(['tenant_id' => $tenant->id, 'component_group_id' => $tenantGroup, 'code' => 'DISC_BRAKE', 'name' => 'Disc Brake', 'status' => 'ACTIVE']);

        $product = $this->postJson('/api/v1/app/products', $this->sparepart($tenant, ['component_group_id' => $tenantGroup, 'component_category_id' => $category->id]), $headers)
            ->assertCreated()->assertJsonPath('data.sku', 'SPR-BRA-000001')->json('data');

        // The group now appears in an issued SKU: its abbreviation is locked, its name is not.
        $this->getJson("/api/v1/app/component-groups/{$tenantGroup}", $headers)->assertJsonPath('data.abbreviation_locked', true);
        $this->putJson("/api/v1/app/component-groups/{$tenantGroup}", ['abbreviation' => 'BRX'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('abbreviation');
        $this->putJson("/api/v1/app/component-groups/{$tenantGroup}", ['name' => 'Braking System'], $headers)->assertOk();

        // Reclassification and product edits never regenerate the SKU.
        $this->putJson("/api/v1/app/products/{$product['id']}", ['name' => 'Renamed', 'sku' => 'HACK-1'] + $this->classificationFor('ENG'), $headers)->assertOk();
        $this->assertSame('SPR-BRA-000001', Product::query()->find($product['id'])->sku);
    }
}
