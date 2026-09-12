<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Organization\Models\Branch;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\WheelConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase D — Tire Asset Integrity: G-26 (serial number integrity),
 * G-25 (wheel position validation), G-23 (legacy tire onboarding),
 * G-24 (atomic multi-tire rotation/swap), G-28 (replacement disposition
 * flexibility).
 */
class TireAssetIntegrityTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TAI-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);

        return [$tenant, $vehicle, $product, $category];
    }

    private function makeTirePermissions(): array
    {
        return ['tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap'];
    }

    // ---- G-26: Serial Number Integrity -------------------------------------------------

    public function test_serial_number_duplicate_by_case_and_whitespace_is_rejected_at_api_layer(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'ABC-123', 'product_id' => $product->id,
        ], $headers)->assertStatus(201);

        $this->postJson('/api/v1/app/tires', [
            'serial_number' => '  abc-123  ', 'product_id' => $product->id,
        ], $headers)->assertStatus(422);
    }

    public function test_serial_number_stored_value_is_trimmed(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/tires', [
            'serial_number' => '  SN-TRIM  ', 'product_id' => $product->id,
        ], $headers)->assertStatus(201);

        $tire = Tire::query()->findOrFail($create->json('data.id'));
        $this->assertSame('SN-TRIM', $tire->serial_number);
    }

    /** VMS finding: "Production Date Code" — stored as-is, never inferred or fabricated. */
    public function test_manufacture_date_code_is_optional_and_stored_verbatim(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $withCode = $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'SN-DATECODE', 'product_id' => $product->id, 'manufacture_date_code' => '2314',
        ], $headers)->assertStatus(201);
        $this->assertSame('2314', Tire::query()->findOrFail($withCode->json('data.id'))->manufacture_date_code);

        $withoutCode = $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'SN-NODATECODE', 'product_id' => $product->id,
        ], $headers)->assertStatus(201);
        $this->assertNull(Tire::query()->findOrFail($withoutCode->json('data.id'))->manufacture_date_code);
    }

    public function test_database_unique_index_rejects_normalized_duplicate_bypassing_request_validation(): void
    {
        [$tenant, , $product] = $this->setUpScenario();

        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'DB-GUARD', 'current_status' => 'IN_STOCK']);

        $this->expectException(QueryException::class);
        DB::table('tires')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'serial_number' => 'db-guard',
            'current_status' => 'IN_STOCK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- G-25: Wheel Position Validation ------------------------------------------------

    public function test_install_to_unconfigured_category_stays_permissive(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-PERMISSIVE', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FREEFORM_POS',
        ], $headers)->assertStatus(201);
    }

    public function test_install_to_configured_category_rejects_unknown_position(): void
    {
        [$tenant, $vehicle, $product, $category] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        WheelConfiguration::query()->create(['tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id, 'position_code' => 'FRONT_LEFT', 'label' => 'Front Left', 'axle_number' => 1, 'sequence' => 1]);
        WheelConfiguration::query()->create(['tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id, 'position_code' => 'FRONT_RIGHT', 'label' => 'Front Right', 'axle_number' => 1, 'sequence' => 2]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-STRICT-BAD', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'REAR_LEFT',
        ], $headers)->assertStatus(422);
    }

    public function test_install_to_configured_category_accepts_known_position(): void
    {
        [$tenant, $vehicle, $product, $category] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        WheelConfiguration::query()->create(['tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id, 'position_code' => 'FRONT_LEFT', 'label' => 'Front Left', 'axle_number' => 1, 'sequence' => 1]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-STRICT-OK', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
        ], $headers)->assertStatus(201);
    }

    // ---- G-23: Legacy Tire Onboarding ---------------------------------------------------

    public function test_legacy_onboarding_with_estimated_date_records_source_and_baseline_inspection(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-LEGACY', 'current_status' => 'IN_STOCK']);

        $installedAt = now()->subMonths(6)->toDateString();
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
            'installed_at' => $installedAt, 'installed_at_source' => 'ESTIMATED',
            'baseline_tread_depth_mm' => 9.5, 'baseline_condition' => 'GOOD',
        ], $headers)->assertStatus(201);

        $installation = $tire->installations()->first();
        $this->assertSame('ESTIMATED', $installation->installation_date_source);

        $inspection = $tire->inspections()->first();
        $this->assertNotNull($inspection);
        $this->assertEquals(9.5, (float) $inspection->tread_depth_mm);
        $this->assertStringContainsString('ESTIMATED', $inspection->recommendation);
    }

    public function test_legacy_onboarding_without_baseline_reading_creates_no_fabricated_inspection(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-LEGACY-UNKNOWN', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
            'installed_at' => now()->subYear()->toDateString(), 'installed_at_source' => 'UNKNOWN',
        ], $headers)->assertStatus(201);

        $this->assertSame(0, $tire->inspections()->count());
    }

    public function test_install_date_in_the_future_is_rejected(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-FUTURE', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
            'installed_at' => now()->addDays(5)->toDateString(),
        ], $headers)->assertStatus(422);
    }

    public function test_install_date_predating_purchase_date_is_rejected(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-PREDATE',
            'current_status' => 'IN_STOCK', 'purchase_date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
            'installed_at' => now()->subMonths(4)->toDateString(),
        ], $headers)->assertStatus(422);
    }

    // ---- G-24: Atomic Multi-Tire Rotation (position swap) -------------------------------

    public function test_swap_positions_exchanges_two_installed_tires_atomically(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tireA = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SWAP-A', 'current_status' => 'IN_STOCK']);
        $tireB = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SWAP-B', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tireA->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tireB->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_RIGHT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tireA->id}/swap-positions", [
            'other_tire_id' => $tireB->id, 'odometer' => 12000,
        ], $headers)->assertStatus(201);

        $tireA->refresh();
        $tireB->refresh();
        $this->assertSame('FRONT_RIGHT', $tireA->current_position);
        $this->assertSame('FRONT_LEFT', $tireB->current_position);
        $this->assertSame(1, $tireA->rotations()->count());
        $this->assertSame(1, $tireB->rotations()->count());

        // Each tire has exactly one active installation after the swap.
        $this->assertSame(1, $tireA->installations()->whereNull('removed_at')->count());
        $this->assertSame(1, $tireB->installations()->whereNull('removed_at')->count());
    }

    public function test_swap_positions_rejects_tires_on_different_vehicles(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        $branch = Branch::query()->where('tenant_id', $tenant->id)->first();
        $category = VehicleCategory::query()->first();
        $vehicle2 = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'REG-SWAP-2']);
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tireA = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-DIFF-A', 'current_status' => 'IN_STOCK']);
        $tireB = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-DIFF-B', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tireA->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tireB->id}/install", ['vehicle_id' => $vehicle2->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tireA->id}/swap-positions", [
            'other_tire_id' => $tireB->id,
        ], $headers)->assertStatus(422);
    }

    public function test_swap_positions_rejects_swapping_tire_with_itself(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELF', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/swap-positions", [
            'other_tire_id' => $tire->id,
        ], $headers)->assertStatus(422);
    }

    // ---- G-28: Replacement Disposition Flexibility --------------------------------------

    public function test_replace_with_scrap_disposition_marks_old_tire_scrapped(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $oldTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-OLD-SCRAP', 'current_status' => 'IN_STOCK']);
        $newTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NEW-SCRAP', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$oldTire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$oldTire->id}/replace", [
            'new_tire_id' => $newTire->id, 'reason' => 'Punctured beyond repair', 'disposition' => 'SCRAP', 'odometer' => 20000,
        ], $headers)->assertStatus(201);

        $oldTire->refresh();
        $newTire->refresh();
        $this->assertSame('SCRAPPED', $oldTire->current_status);
        $this->assertSame('INSTALLED', $newTire->current_status);
        $this->assertSame('FRONT_LEFT', $newTire->current_position);

        $removal = $oldTire->removals()->first();
        $this->assertSame('SCRAP', $removal->disposition);
        $this->assertSame($newTire->id, $removal->replaced_by_tire_id);
    }

    public function test_replace_with_retread_disposition_marks_old_tire_retread(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $oldTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-OLD-RETREAD', 'current_status' => 'IN_STOCK']);
        $newTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NEW-RETREAD', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$oldTire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$oldTire->id}/replace", [
            'new_tire_id' => $newTire->id, 'reason' => 'Worn but retreadable', 'disposition' => 'RETREAD',
        ], $headers)->assertStatus(201);

        $oldTire->refresh();
        $this->assertSame('RETREAD', $oldTire->current_status);
    }

    public function test_replace_with_reuse_disposition_marks_old_tire_removed(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $oldTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-OLD-REUSE', 'current_status' => 'IN_STOCK']);
        $newTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NEW-REUSE', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$oldTire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$oldTire->id}/replace", [
            'new_tire_id' => $newTire->id, 'reason' => 'Vehicle wheel balancing swap', 'disposition' => 'REUSE',
        ], $headers)->assertStatus(201);

        $oldTire->refresh();
        $this->assertSame('REMOVED', $oldTire->current_status);
    }

    public function test_replace_requires_a_valid_disposition(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $oldTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-OLD-NODISP', 'current_status' => 'IN_STOCK']);
        $newTire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NEW-NODISP', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$oldTire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$oldTire->id}/replace", [
            'new_tire_id' => $newTire->id, 'reason' => 'Missing disposition',
        ], $headers)->assertStatus(422);
    }
}
