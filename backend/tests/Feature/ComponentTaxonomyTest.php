<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\ComponentSubcategory;
use App\Domain\MasterData\Services\ComponentClassificationService;
use App\Domain\MasterData\Support\ComponentTaxonomyBaseline;
use App\Domain\ProductMaster\Models\Product;
use Database\Seeders\ComponentGroupSeeder;
use Database\Seeders\ComponentTaxonomySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Full Component Classification taxonomy (Component Group -> Category ->
 * Subcategory): completeness against the authoritative reference document,
 * production-safe seeding, master-data lifecycle, Product hierarchy / Item
 * Type validation, soft-delete history and SKU invariance.
 */
class ComponentTaxonomyTest extends TestCase
{
    private const GROUP_BY_SECTION = [
        'A' => 'CG-ENGINE', 'B' => 'CG-ENGINE-LUBE', 'C' => 'CG-CLUTCH', 'D' => 'CG-ENGINE-COOL', 'E' => 'CG-ENGINE-FUEL',
        'F' => 'CG-TRANS', 'G' => 'CG-ENGINE-EXHAUST', 'H' => 'CG-STEER', 'I' => 'CG-AXLE', 'J' => 'CG-FRAME',
        'K' => 'CG-ELEC', 'L' => 'CG-BRAKE', 'M' => 'CG-SUSP', 'N' => 'CG-HYD', 'O' => 'CG-PNEU', 'P' => 'CG-SWING',
        'Q' => 'CG-UC', 'R' => 'CG-TYRE', 'S' => 'CG-ATTACH', 'T' => 'CG-ACC',
        'U1' => 'CG-HVAC', 'U2' => 'CG-BODY', 'U3' => 'CG-GLASS', 'U4' => 'CG-SRS', 'U5' => 'CG-EV-HV',
    ];

    private const MANAGE = [
        'component_group.view',
        'component_category.view', 'component_category.create', 'component_category.update', 'component_category.delete',
        'component_subcategory.view', 'component_subcategory.create', 'component_subcategory.update', 'component_subcategory.delete',
    ];

    private function seedTaxonomy(): void
    {
        $this->seed(ComponentGroupSeeder::class);
        $this->seed(ComponentTaxonomySeeder::class);
    }

    private function groupId(string $code): string
    {
        return ComponentGroup::withTrashed()->whereNull('tenant_id')->where('code', $code)->value('id');
    }

    private function category(string $group, string $code): ComponentCategory
    {
        return ComponentCategory::withTrashed()->whereNull('tenant_id')->where('component_group_id', $this->groupId($group))->where('code', $code)->firstOrFail();
    }

    private function subcategory(string $group, string $category, string $code): ComponentSubcategory
    {
        return ComponentSubcategory::withTrashed()->whereNull('tenant_id')->where('component_category_id', $this->category($group, $category)->id)->where('code', $code)->firstOrFail();
    }

    private function classification(string $group, string $category, string $subcategory): array
    {
        return [
            'component_group_id' => $this->groupId($group),
            'component_category_id' => $this->category($group, $category)->id,
            'component_subcategory_id' => $this->subcategory($group, $category, $subcategory)->id,
        ];
    }

    private function tenantWithUser(array $permissions = []): array
    {
        $tenant = $this->makeTenant(['code' => 'TAX-'.Str::upper(Str::random(4))]);
        $this->grantModule($tenant, 'CORE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, array_merge(['product.view', 'product.create', 'product.update', 'warehouse.view'], $permissions));

        return [$tenant, $this->authHeaders($token)];
    }

    private function sparepartPayload($tenant, array $overrides = []): array
    {
        return array_merge([
            'sku' => 'SPR-BRK-'.Str::upper(Str::random(6)), 'name' => 'Brake Pad Front',
            'product_category_id' => $this->makeProductCategory()->id,
            'product_type' => 'SPARE_PART',
            'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->makeWarehouseBin($tenant)->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-'.Str::random(5), 'part_type' => 'GENUINE', 'compatibilities' => [['vehicle_brand' => 'Hino', 'vehicle_model' => 'Ranger']]],
        ], $overrides);
    }

    /** Independent PHP re-parse of the authoritative reference tables (same rules as the transcription). */
    private function referenceRows(): array
    {
        $rows = [];
        $group = null;
        foreach (explode("\n", file_get_contents(base_path('../docs/reference/component-taxonomy.md'))) as $line) {
            if (preg_match('/^#{1,2}\s+([A-Z]\d?)\.\s+/', $line, $m)) {
                if ($m[1] === 'V') {
                    break;
                }
                $group = self::GROUP_BY_SECTION[$m[1]] ?? null;

                continue;
            }
            if ($group === null || ! str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if (trim(implode('', $cells), '-: ') === '' || in_array($cells[0], ['Category', 'Category / Assembly'], true)) {
                continue;
            }
            $rows[] = [$group, $cells[0], $cells[1]];
        }

        return $rows;
    }

    // ---------------------------------------------------------------- completeness & seeding

    public function test_seeded_taxonomy_matches_every_row_of_the_reference_document(): void
    {
        $reference = $this->referenceRows();
        $this->assertCount(917, $reference);
        $this->assertSame(['groups' => 25, 'categories' => 334, 'subcategories' => 917, 'item_type_mappings' => 855], ComponentTaxonomyBaseline::statistics());

        $this->seedTaxonomy();

        $seeded = DB::table('component_subcategories as s')
            ->join('component_categories as c', 'c.id', '=', 's.component_category_id')
            ->join('component_groups as g', 'g.id', '=', 'c.component_group_id')
            ->whereNull('s.tenant_id')
            ->get(['g.code as g', 'c.name as c', 's.name as s'])
            ->map(fn ($r) => $r->g.'|'.$r->c.'|'.$r->s)
            ->all();

        $expected = array_map(fn ($r) => implode('|', $r), $reference);
        $this->assertSame([], array_values(array_diff($expected, $seeded)), 'Reference rows missing from the seeded taxonomy.');
        $this->assertSame([], array_values(array_diff($seeded, $expected)), 'Seeded rows not present in the reference.');
        $this->assertCount(917, array_unique($seeded));

        $this->assertSame(25, DB::table('component_categories')->distinct()->count('component_group_id'));
        $this->assertSame(334, DB::table('component_categories')->count());
        $this->assertSame(855, DB::table('component_subcategory_item_types')->count());
    }

    public function test_required_depth_examples_and_explicit_item_types(): void
    {
        $this->seedTaxonomy();

        $valveTrain = $this->category('CG-ENGINE', 'VALVE_TRAIN');
        $this->assertSame(
            ['Intake Valve', 'Exhaust Valve', 'Valve Guide', 'Valve Seat', 'Valve Spring', 'Valve Keeper', 'Valve Stem Seal', 'Rocker Arm', 'Rocker Shaft', 'Push Rod', 'Tappet / Lifter', 'Lash Adjuster'],
            ComponentSubcategory::query()->where('component_category_id', $valveTrain->id)->orderBy('sequence')->pluck('name')->all()
        );
        $this->assertSame(
            ['Disc Brake', 'Drum Brake', 'Hydraulic Brake', 'Brake Booster', 'ABS', 'Parking Brake', 'EPB', 'Air Brake', 'Retarder', 'Fluid', 'Chemical', 'Lubricant'],
            ComponentCategory::query()->where('component_group_id', $this->groupId('CG-BRAKE'))->orderBy('sequence')->pluck('name')->all()
        );

        $this->assertSame(['SPARE_PART'], $this->subcategory('CG-ENGINE', 'VALVE_TRAIN', 'EXHAUST_VALVE')->itemTypes());
        $this->assertSame(['CONSUMABLE'], $this->subcategory('CG-ENGINE', 'ENGINE_SERVICE', 'GASKET_MAKER')->itemTypes());
        $this->assertSame(['SPARE_PART'], $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')->itemTypes());
        $this->assertSame(['CONSUMABLE'], $this->subcategory('CG-ENGINE-LUBE', 'ENGINE_LUBRICANT', 'FULLY_SYNTHETIC_ENGINE_OIL')->itemTypes());
        $this->assertSame(['TIRE'], $this->subcategory('CG-TYRE', 'TRUCK_TIRE', 'DRIVE_TIRE')->itemTypes());
        $this->assertSame(['RIM'], $this->subcategory('CG-TYRE', 'STEEL_RIM', 'TRUCK_BUS_STEEL_RIM')->itemTypes());
        $this->assertSame(['SPARE_PART'], $this->subcategory('CG-TYRE', 'WHEEL_FASTENER', 'WHEEL_STUD')->itemTypes());
        $this->assertSame(['CONSUMABLE'], $this->subcategory('CG-TYRE', 'CONSUMABLE', 'VULCANIZING_CEMENT')->itemTypes());
        $this->assertSame([], $this->subcategory('CG-ATTACH', 'BUCKET', 'ROCK_BUCKET')->itemTypes(), 'Ambiguous rows stay unrestricted.');
        $this->assertSame('Examples: Hydraulic/mechanical lifter', $this->subcategory('CG-ENGINE', 'VALVE_TRAIN', 'TAPPET_LIFTER')->description);
    }

    public function test_seeder_is_idempotent_and_never_takes_back_ownership(): void
    {
        $this->seedTaxonomy();
        $tenant = $this->makeTenant();

        $disc = $this->category('CG-BRAKE', 'DISC_BRAKE');
        $disc->update(['name' => 'Disc Braking (curated)', 'status' => 'INACTIVE']);
        $drum = $this->category('CG-BRAKE', 'DRUM_BRAKE');
        $drum->delete();
        $pad = $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD');
        $pad->syncItemTypes(['SPARE_PART', 'CONSUMABLE']);
        $rotor = $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_DISC_ROTOR');
        $rotor->delete();
        $custom = ComponentCategory::query()->create(['tenant_id' => $tenant->id, 'component_group_id' => $this->groupId('CG-BRAKE'), 'code' => 'REGENERATIVE_BRAKE', 'name' => 'Regenerative Brake', 'status' => 'ACTIVE']);
        $customSub = ComponentSubcategory::query()->create(['tenant_id' => $tenant->id, 'component_category_id' => $custom->id, 'code' => 'BRAKE_BLENDING_CONTROLLER', 'name' => 'Brake Blending Controller', 'status' => 'ACTIVE']);
        $product = $this->makeProduct($tenant, null, null, ['sku' => 'SPR-BRK-000123'] + $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));

        // A baseline row that has never existed (e.g. added in a later release) must still be added.
        $caliperPin = $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'CALIPER_GUIDE_PIN');
        DB::table('component_subcategory_item_types')->where('component_subcategory_id', $caliperPin->id)->delete();
        DB::table('component_subcategories')->where('id', $caliperPin->id)->delete();

        $this->seed(ComponentTaxonomySeeder::class);
        $this->seed(ComponentTaxonomySeeder::class);

        $this->assertSame(334 + 1, ComponentCategory::withTrashed()->count());
        $this->assertSame(917 + 1, ComponentSubcategory::withTrashed()->count());
        $this->assertSame(['Disc Braking (curated)', 'INACTIVE'], [$disc->fresh()->name, $disc->fresh()->status]);
        $this->assertTrue(ComponentCategory::withTrashed()->find($drum->id)->trashed());
        $this->assertTrue(ComponentSubcategory::withTrashed()->find($rotor->id)->trashed());
        $this->assertSame(['CONSUMABLE', 'SPARE_PART'], $pad->itemTypes());
        $this->assertNotNull($custom->fresh());
        $this->assertNotNull($customSub->fresh());
        $recreated = $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'CALIPER_GUIDE_PIN');
        $this->assertSame(['SPARE_PART'], $recreated->itemTypes());
        $this->assertSame('SPR-BRK-000123', $product->fresh()->sku);
        $this->assertSame($pad->id, $product->fresh()->component_subcategory_id);
    }

    // ---------------------------------------------------------------- master data management

    public function test_tenant_manages_own_categories_and_subcategories_but_not_the_baseline(): void
    {
        $this->seedTaxonomy();
        [, $headers] = $this->tenantWithUser(self::MANAGE);
        $brake = $this->groupId('CG-BRAKE');

        $categoryId = $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'regenerative_brake', 'name' => 'Regenerative Brake'], $headers)
            ->assertCreated()->assertJsonPath('data.code', 'REGENERATIVE_BRAKE')->assertJsonPath('data.is_system', false)->json('data.id');
        $subId = $this->postJson('/api/v1/app/component-subcategories', ['component_category_id' => $categoryId, 'code' => 'BRAKE_BLENDING_CONTROLLER', 'name' => 'Brake Blending Controller', 'item_types' => ['SPARE_PART']], $headers)
            ->assertCreated()->assertJsonPath('data.item_types', ['SPARE_PART'])->json('data.id');

        // Validation: duplicate code (same parent), duplicate name, invalid code format, retired/unknown parent.
        $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'DISC_BRAKE', 'name' => 'Other'], $headers)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'DISC_2', 'name' => 'disc brake'], $headers)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'BAD CODE', 'name' => 'X'], $headers)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/app/component-subcategories', ['component_category_id' => $categoryId, 'code' => 'X', 'name' => 'Y', 'item_types' => ['WHEELBARROW']], $headers)->assertStatus(422)->assertJsonValidationErrors('item_types.0');
        // The same code under a different parent is fine (scoped uniqueness).
        $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $this->groupId('CG-ENGINE'), 'code' => 'REGENERATIVE_BRAKE', 'name' => 'Regenerative Brake'], $headers)->assertCreated();

        $this->putJson("/api/v1/app/component-categories/{$categoryId}", ['name' => 'Regen Brake', 'sequence' => 5], $headers)->assertOk()->assertJsonPath('data.name', 'Regen Brake');
        $this->putJson("/api/v1/app/component-subcategories/{$subId}", ['item_types' => ['SPARE_PART', 'EQUIPMENT']], $headers)->assertOk()->assertJsonPath('data.item_types', ['EQUIPMENT', 'SPARE_PART']);

        $pad = $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD');
        $this->putJson("/api/v1/app/component-subcategories/{$pad->id}", ['name' => 'x'], $headers)->assertStatus(403);
        $this->deleteJson("/api/v1/app/component-categories/{$this->category('CG-BRAKE', 'DISC_BRAKE')->id}", [], $headers)->assertStatus(403);

        $list = $this->getJson('/api/v1/app/component-subcategories?component_group_id='.$brake.'&item_type=SPARE_PART&per_page=200', $headers)->assertOk();
        $this->assertContains('Brake Pad', collect($list->json('data'))->pluck('name'));
        $this->assertContains('Brake Blending Controller', collect($list->json('data'))->pluck('name'));
        $this->assertNotContains('Brake Fluid', collect($list->json('data'))->pluck('name'));

        $this->deleteJson("/api/v1/app/component-subcategories/{$subId}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/app/component-categories/{$categoryId}", [], $headers)->assertOk();
        $this->getJson('/api/v1/app/component-categories?trashed=only', $headers)->assertJsonPath('data.0.id', $categoryId)->assertJsonPath('data.0.is_deleted', true);
        // Restoring the parent never restores a child the user deleted itself.
        $this->postJson("/api/v1/app/component-categories/{$categoryId}/restore", [], $headers)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertTrue(ComponentSubcategory::withTrashed()->find($subId)->trashed());

        $actions = AuditLog::query()->where('resource_id', $subId)->pluck('action')->all();
        foreach (['created', 'item_types_changed', 'deactivated'] as $expected) {
            $this->assertContains($expected, $actions);
        }
        $this->assertContains('restored', AuditLog::query()->where('resource_id', $categoryId)->pluck('action')->all());
    }

    public function test_management_requires_permissions_and_other_tenants_rows_are_invisible(): void
    {
        $this->seedTaxonomy();
        [, $viewer] = $this->tenantWithUser(['component_category.view', 'component_subcategory.view']);
        [$tenantB, $managerB] = $this->tenantWithUser(self::MANAGE);
        $brake = $this->groupId('CG-BRAKE');

        $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'X_CAT', 'name' => 'X'], $viewer)->assertStatus(403);
        $idB = $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'B_ONLY', 'name' => 'B Only'], $managerB)->assertCreated()->json('data.id');

        $this->getJson("/api/v1/app/component-categories/{$idB}", $viewer)->assertStatus(404);
        $this->assertNotContains('B_ONLY', collect($this->getJson('/api/v1/app/component-categories?per_page=500', $viewer)->json('data'))->pluck('code'));
        $this->assertSame($tenantB->id, ComponentCategory::withoutGlobalScopes()->find($idB)->tenant_id);
    }

    public function test_used_category_and_subcategory_cannot_be_reparented_but_unused_can(): void
    {
        $this->seedTaxonomy();
        [$tenant, $headers] = $this->tenantWithUser(self::MANAGE);
        $brake = $this->groupId('CG-BRAKE');
        $engine = $this->groupId('CG-ENGINE');

        $categoryId = $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $brake, 'code' => 'CUSTOM_ASSY', 'name' => 'Custom Assy'], $headers)->json('data.id');
        $subId = $this->postJson('/api/v1/app/component-subcategories', ['component_category_id' => $categoryId, 'code' => 'CUSTOM_PART', 'name' => 'Custom Part'], $headers)->json('data.id');

        // Unused: re-parent allowed.
        $this->putJson("/api/v1/app/component-categories/{$categoryId}", ['component_group_id' => $engine], $headers)->assertOk()->assertJsonPath('data.component_group_id', $engine);

        $this->makeProduct($tenant, null, null, ['component_group_id' => $engine, 'component_category_id' => $categoryId, 'component_subcategory_id' => $subId]);

        $this->putJson("/api/v1/app/component-categories/{$categoryId}", ['component_group_id' => $brake], $headers)->assertStatus(422)->assertJsonValidationErrors('component_group_id');
        $otherCategory = $this->postJson('/api/v1/app/component-categories', ['component_group_id' => $engine, 'code' => 'OTHER_ASSY', 'name' => 'Other Assy'], $headers)->json('data.id');
        $this->putJson("/api/v1/app/component-subcategories/{$subId}", ['component_category_id' => $otherCategory], $headers)->assertStatus(422)->assertJsonValidationErrors('component_category_id');
        // Renaming a used row is fine.
        $this->putJson("/api/v1/app/component-subcategories/{$subId}", ['name' => 'Custom Part Renamed'], $headers)->assertOk();
        // Narrowing applicability below what existing Products use is rejected.
        $this->putJson("/api/v1/app/component-subcategories/{$subId}", ['item_types' => ['TIRE']], $headers)->assertStatus(422)->assertJsonValidationErrors('item_types');
        $this->putJson("/api/v1/app/component-subcategories/{$subId}", ['item_types' => ['SPARE_PART']], $headers)->assertOk();
    }

    public function test_platform_admin_manages_the_baseline_taxonomy(): void
    {
        $this->seedTaxonomy();
        [, $token] = $this->makePlatformUser(['component_category.view', 'component_category.create', 'component_category.update', 'component_category.delete', 'component_subcategory.view', 'component_subcategory.create', 'component_subcategory.update', 'component_subcategory.delete']);
        $headers = $this->authHeaders($token);
        $pad = $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD');

        $this->putJson("/api/v1/platform/component-subcategories/{$pad->id}", ['name' => 'Disc Brake Pad'], $headers)->assertOk()->assertJsonPath('data.name', 'Disc Brake Pad');
        $id = $this->postJson('/api/v1/platform/component-categories', ['component_group_id' => $this->groupId('CG-BRAKE'), 'code' => 'REGENERATIVE_BRAKE', 'name' => 'Regenerative Brake'], $headers)
            ->assertCreated()->assertJsonPath('data.is_system', true)->json('data.id');
        $this->assertNull(ComponentCategory::query()->find($id)->tenant_id);
        $this->deleteJson("/api/v1/platform/component-categories/{$id}", [], $headers)->assertOk();
        $this->postJson("/api/v1/platform/component-categories/{$id}/restore", [], $headers)->assertOk();

        $this->assertSame(334 + 1, $this->getJson('/api/v1/platform/component-categories?per_page=1', $headers)->json('meta.total'));
    }

    // ---------------------------------------------------------------- product classification

    public function test_product_hierarchy_must_be_consistent(): void
    {
        $this->seedTaxonomy();
        $service = app(ComponentClassificationService::class);

        $this->assertSame($this->classification('CG-ENGINE', 'VALVE_TRAIN', 'EXHAUST_VALVE'), $service->resolveProductClassification('SPARE_PART', $this->classification('CG-ENGINE', 'VALVE_TRAIN', 'EXHAUST_VALVE')));
        $service->resolveProductClassification('SPARE_PART', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));

        $brkValve = ['component_group_id' => $this->groupId('CG-BRAKE')] + array_slice($this->classification('CG-ENGINE', 'VALVE_TRAIN', 'EXHAUST_VALVE'), 1, null, true);
        $this->assertClassificationRejected('SPARE_PART', $brkValve, 'component_category_id');

        $engDisc = ['component_group_id' => $this->groupId('CG-ENGINE')] + array_slice($this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), 1, null, true);
        $this->assertClassificationRejected('SPARE_PART', $engDisc, 'component_category_id');

        $brakeWithFuelInjector = $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD');
        $brakeWithFuelInjector['component_subcategory_id'] = $this->subcategory('CG-ENGINE-FUEL', 'INJECTION', 'FUEL_INJECTOR')->id;
        $this->assertClassificationRejected('SPARE_PART', $brakeWithFuelInjector, 'component_subcategory_id');

        $this->assertClassificationRejected('SPARE_PART', ['component_subcategory_id' => $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')->id], 'component_category_id');
        // Tools/Equipment (or any product) may stay unclassified.
        $this->assertSame(['component_group_id' => null, 'component_category_id' => null, 'component_subcategory_id' => null], $service->resolveProductClassification('TOOL', []));
    }

    public function test_item_type_applicability_is_enforced(): void
    {
        $this->seedTaxonomy();
        $service = app(ComponentClassificationService::class);

        $service->resolveProductClassification('SPARE_PART', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));
        $service->resolveProductClassification('TIRE', $this->classification('CG-TYRE', 'TRUCK_TIRE', 'DRIVE_TIRE'));
        $service->resolveProductClassification('RIM', $this->classification('CG-TYRE', 'STEEL_RIM', 'TRUCK_BUS_STEEL_RIM'));
        $service->resolveProductClassification('CONSUMABLE', $this->classification('CG-ENGINE-LUBE', 'ENGINE_LUBRICANT', 'FULLY_SYNTHETIC_ENGINE_OIL'));
        $service->resolveProductClassification('EQUIPMENT', $this->classification('CG-ATTACH', 'BUCKET', 'ROCK_BUCKET'));

        $this->assertClassificationRejected('TIRE', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), 'component_subcategory_id');
        $this->assertClassificationRejected('TIRE', $this->classification('CG-ENGINE', 'VALVE_TRAIN', 'EXHAUST_VALVE'), 'component_subcategory_id');
        $this->assertClassificationRejected('SPARE_PART', $this->classification('CG-TYRE', 'TRUCK_TIRE', 'DRIVE_TIRE'), 'component_subcategory_id');
    }

    public function test_product_api_create_stores_classification_and_keeps_the_sku_as_issued(): void
    {
        $this->seedTaxonomy();
        [$tenant, $headers] = $this->tenantWithUser();

        $created = $this->postJson('/api/v1/app/products', $this->sparepartPayload($tenant, ['sku' => 'SPR-BRK-000123'] + $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')), $headers)
            ->assertCreated()->assertJsonPath('data.sku', 'SPR-BRK-000123');
        $id = $created->json('data.id');

        $this->postJson('/api/v1/app/products', $this->sparepartPayload($tenant, ['product_type' => 'TIRE'] + $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_subcategory_id');
        $this->postJson('/api/v1/app/products', $this->sparepartPayload($tenant, ['component_group_id' => $this->groupId('CG-ENGINE')] + array_slice($this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), 1, null, true)), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_category_id');

        $show = $this->getJson("/api/v1/app/products/{$id}", $headers)->assertOk();
        $this->assertSame('BRK', $show->json('data.component_group.abbreviation'));
        $this->assertSame('Disc Brake', $show->json('data.component_category.name'));
        $this->assertSame('Brake Pad', $show->json('data.component_subcategory.name'));

        $this->assertSame([$id], collect($this->getJson('/api/v1/app/products?component_subcategory_id='.$this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')->id, $headers)->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/v1/app/products?component_category_id='.$this->category('CG-ENGINE', 'VALVE_TRAIN')->id, $headers)->json('data'));

        // Renaming master data and re-seeding never regenerate the SKU.
        $this->category('CG-BRAKE', 'DISC_BRAKE')->update(['name' => 'Disc Braking']);
        $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')->update(['name' => 'Disc Brake Pad']);
        $this->seedTaxonomy();
        $this->assertSame('SPR-BRK-000123', Product::query()->find($id)->sku);
        $this->assertSame('Disc Braking', $this->getJson("/api/v1/app/products/{$id}", $headers)->json('data.component_category.name'));
    }

    public function test_soft_deleted_category_stays_resolvable_but_is_unavailable_for_new_products(): void
    {
        $this->seedTaxonomy();
        [$tenant, $headers] = $this->tenantWithUser();
        $product = $this->makeProduct($tenant, null, null, ['sku' => 'SPR-BRK-000124'] + $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));
        $disc = $this->category('CG-BRAKE', 'DISC_BRAKE');
        app(ComponentClassificationService::class)->delete($disc);

        $show = $this->getJson("/api/v1/app/products/{$product->id}", $headers)->assertOk();
        $this->assertSame(['BRK', 'Disc Brake', 'Brake Pad', 'SPR-BRK-000124'], [
            $show->json('data.component_group.abbreviation'), $show->json('data.component_category.name'),
            $show->json('data.component_subcategory.name'), $show->json('data.sku'),
        ]);
        $this->assertNotNull($show->json('data.component_category.deleted_at'));
        $this->assertFalse($this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')->trashed(), 'Children are not cascade-deleted.');

        $this->assertClassificationRejected('SPARE_PART', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), 'component_category_id');
        $categories = $this->getJson('/api/v1/app/product-classification/categories?component_group_id='.$this->groupId('CG-BRAKE'), $headers)->assertOk();
        $this->assertNotContains('Disc Brake', collect($categories->json('data'))->pluck('name'));
        $this->assertSame([], $this->getJson('/api/v1/app/product-classification/subcategories?component_category_id='.$disc->id, $headers)->json('data'));
        // Other Categories of the group remain usable.
        app(ComponentClassificationService::class)->resolveProductClassification('SPARE_PART', $this->classification('CG-BRAKE', 'DRUM_BRAKE', 'BRAKE_SHOE'));
    }

    public function test_soft_deleted_subcategory_leaves_siblings_selectable(): void
    {
        $this->seedTaxonomy();
        [$tenant, $headers] = $this->tenantWithUser();
        $product = $this->makeProduct($tenant, null, null, $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));
        app(ComponentClassificationService::class)->delete($this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));

        $this->assertSame('Brake Pad', $this->getJson("/api/v1/app/products/{$product->id}", $headers)->json('data.component_subcategory.name'));
        $this->assertClassificationRejected('SPARE_PART', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), 'component_subcategory_id');
        app(ComponentClassificationService::class)->resolveProductClassification('SPARE_PART', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_CALIPER'));

        $names = collect($this->getJson('/api/v1/app/product-classification/subcategories?item_type=SPARE_PART&component_category_id='.$this->category('CG-BRAKE', 'DISC_BRAKE')->id, $headers)->json('data'));
        $this->assertNotContains('Brake Pad', $names->pluck('name'));
        $this->assertTrue($names->firstWhere('name', 'Brake Caliper')['allowed']);
    }

    public function test_inactive_component_group_blocks_its_whole_subtree_without_touching_children(): void
    {
        $this->seedTaxonomy();
        [$tenant, $headers] = $this->tenantWithUser();
        $product = $this->makeProduct($tenant, null, null, $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));
        ComponentGroup::query()->whereKey($this->groupId('CG-BRAKE'))->update(['status' => 'INACTIVE']);

        $this->assertClassificationRejected('SPARE_PART', $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), 'component_group_id');
        $this->assertSame(0, ComponentCategory::query()->where('component_group_id', $this->groupId('CG-BRAKE'))->effectivelyActive()->count());
        $this->assertSame(12, ComponentCategory::query()->where('component_group_id', $this->groupId('CG-BRAKE'))->where('status', 'ACTIVE')->count());
        $this->assertSame('Disc Brake', $this->getJson("/api/v1/app/products/{$product->id}", $headers)->json('data.component_category.name'));
    }

    public function test_product_update_keeps_a_retired_classification_until_it_is_changed(): void
    {
        $this->seedTaxonomy();
        [$tenant, $headers] = $this->tenantWithUser();
        $product = $this->makeProduct($tenant, null, null, ['sku' => 'SPR-BRK-000125'] + $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));
        app(ComponentClassificationService::class)->delete($this->category('CG-BRAKE', 'DISC_BRAKE'));

        // Unrelated edit (even resending the unchanged classification) still saves.
        $this->putJson("/api/v1/app/products/{$product->id}", ['description' => 'Updated'], $headers)->assertOk();
        $this->putJson("/api/v1/app/products/{$product->id}", ['brand' => 'Akebono'] + $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'), $headers)->assertOk();

        // Changing to another retired row, or a stale mismatched hierarchy, is rejected.
        $this->putJson("/api/v1/app/products/{$product->id}", ['component_subcategory_id' => $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_CALIPER')->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_subcategory_id');
        $this->putJson("/api/v1/app/products/{$product->id}", ['component_group_id' => $this->groupId('CG-ENGINE')], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_category_id');

        // Reclassifying to a live, consistent hierarchy works; clearing it works; SKU never changes.
        $this->putJson("/api/v1/app/products/{$product->id}", $this->classification('CG-BRAKE', 'DRUM_BRAKE', 'BRAKE_SHOE'), $headers)->assertOk();
        $this->assertSame('SPR-BRK-000125', $product->fresh()->sku);
        $this->putJson("/api/v1/app/products/{$product->id}", ['component_group_id' => null, 'component_category_id' => null, 'component_subcategory_id' => null], $headers)->assertOk();
        $this->assertNull($product->fresh()->component_group_id);
        $this->assertSame('SPR-BRK-000125', $product->fresh()->sku);
    }

    public function test_classification_lookups_need_only_product_view(): void
    {
        $this->seedTaxonomy();
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'INVENTORY');
        [, $token] = $this->makeTenantUser($tenant, ['product.view']);
        $headers = $this->authHeaders($token);

        $groups = collect($this->getJson('/api/v1/app/product-classification/component-groups', $headers)->assertOk()->json('data'));
        $this->assertSame('BRK', $groups->firstWhere('code', 'CG-BRAKE')['abbreviation']);
        $this->assertCount(12, $this->getJson('/api/v1/app/product-classification/categories?component_group_id='.$this->groupId('CG-BRAKE'), $headers)->json('data'));
        $subs = collect($this->getJson('/api/v1/app/product-classification/subcategories?item_type=TIRE&component_category_id='.$this->category('CG-BRAKE', 'DISC_BRAKE')->id, $headers)->json('data'));
        $this->assertCount(6, $subs);
        $this->assertFalse($subs->firstWhere('name', 'Brake Pad')['allowed']);
        $this->getJson('/api/v1/app/component-categories', $headers)->assertStatus(403);
    }

    public function test_hard_delete_of_referenced_taxonomy_is_impossible(): void
    {
        $this->seedTaxonomy();
        $tenant = $this->makeTenant();
        $this->makeProduct($tenant, null, null, $this->classification('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD'));

        foreach ([
            fn () => $this->subcategory('CG-BRAKE', 'DISC_BRAKE', 'BRAKE_PAD')->forceDelete(),
            fn () => DB::table('component_categories')->where('id', $this->category('CG-BRAKE', 'DISC_BRAKE')->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Expected the database to reject the hard delete.');
            } catch (\Illuminate\Database\QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_permission_backfill_mirrors_component_group_actions(): void
    {
        $tenant = $this->makeTenant();
        $role = \App\Domain\AccessControl\Models\Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Group Viewer', 'scope' => 'tenant', 'is_system' => false]);
        $role->permissions()->sync(\App\Domain\AccessControl\Models\Permission::query()->where('scope', 'tenant')->whereIn('name', ['component_group.view', 'component_group.update'])->pluck('id'));

        $migration = require database_path('migrations/2026_09_29_000007_grant_component_classification_permissions.php');
        $migration->up();
        $migration->up();

        $names = $role->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['component_category.update', 'component_category.view', 'component_group.update', 'component_group.view', 'component_subcategory.update', 'component_subcategory.view'], $names);
    }

    private function assertClassificationRejected(string $productType, array $input, string $field): void
    {
        try {
            app(ComponentClassificationService::class)->resolveProductClassification($productType, $input);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));

            return;
        }
        $this->fail("Expected classification to be rejected on {$field}.");
    }
}
