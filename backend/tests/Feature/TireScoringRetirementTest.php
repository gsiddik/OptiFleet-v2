<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireSale;
use App\Domain\Tire\Models\TireScoringResult;
use App\Domain\Tire\Models\TireSpeedRating;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tire Scoring is retired in favour of the Used Tire Inspection engine:
 *  - selling a tire for operational reuse requires an approved REUSE inspection (no scoring result);
 *  - scrap / casing sales and the sale invariants are unchanged;
 *  - the scoring API and configuration type are gone for writes; legacy scoring configurations,
 *    results and the sales that referenced them stay as readable history;
 *  - retired permissions are no longer offered but existing rows / role assignments are kept.
 */
class TireScoringRetirementTest extends TestCase
{
    private const PERMISSIONS = [
        'tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.inspect', 'tire.sell',
        'tire_used_inspection.approve', 'tire_rule_profile.manage', 'configuration.view', 'configuration_history.view', 'role.view',
    ];

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TSR-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE', 'ACCESS_MANAGEMENT'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch, $this->makeWorkshop($tenant, $branch));
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 77 TSR']);
        [$user, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Highway Rib', 'brand' => 'Bridgestone', 'reference_tread_depth_mm' => '16.00']);
        ProductTireSpec::query()->create([
            'product_id' => $product->id, 'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'R150', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
            'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS', 'tire_size_computed' => '295/80 R22.5',
            'single_load_index_id' => TireLoadIndex::query()->firstOrCreate(['code' => '152'], ['max_load_single_kg' => 3550, 'status' => 'ACTIVE'])->id,
            'speed_rating_id' => TireSpeedRating::query()->firstOrCreate(['code' => 'M'], ['max_speed_kmh' => 130, 'status' => 'ACTIVE'])->id,
        ]);
        $s = ['tenant' => $tenant, 'warehouse' => $warehouse, 'vehicle' => $vehicle, 'product' => $product, 'user' => $user, 'headers' => $this->authHeaders($token)];
        $this->postJson('/api/v1/app/tire-rule-profiles', [
            'name' => 'Truck / Bus', 'tire_category' => 'TRUCK_BUS', 'd_service_mm' => '3', 'd_pull_mm' => '5', 'a_max_months' => 120,
            'a_retread_max_months' => 84, 'n_retread_max' => 2,
            'repair_limits' => ['allowed_locations' => ['TREAD'], 'max_puncture_diameter_mm' => 10, 'max_cut_length_mm' => 25, 'max_cut_width_mm' => 5,
                'max_cut_depth_mm' => 8, 'max_repairs' => 2, 'allow_overlap_previous_repair' => false, 'allow_reinforcement_damage' => false],
        ], $s['headers'])->assertStatus(201);

        return $s;
    }

    private function removedTire(array $s, string $serial): Tire
    {
        $tire = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => $serial, 'manufacture_date_code' => '2322', 'construction_type' => 'RADIAL', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'RL', 'odometer' => 1000], $s['headers'])->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Worn', 'disposition' => 'REUSE', 'odometer' => 6000], $s['headers'])->assertSuccessful();

        return $tire->fresh();
    }

    /** Inspect a removed tire with good answers (→ REUSE) and approve it into the warehouse. */
    private function inspectReuse(array $s, Tire $tire): string
    {
        $points = [];
        foreach ([1, 2, 3] as $zone) {
            foreach (['INNER_MAIN', 'OUTER_MAIN'] as $groove) {
                $points[] = ['zone' => $zone, 'groove' => $groove, 'depth_mm' => '11.0'];
            }
        }
        $id = $this->postJson("/api/v1/app/tires/{$tire->id}/used-inspections", [
            'identity_status' => 'COMPLETE', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN', 'bulge_separation' => 'NONE',
            'cord_exposure' => 'NONE', 'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL', 'inner_liner_condition' => 'NORMAL',
            'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO', 'previous_repair' => 'NONE', 'age_chemical' => 'NONE',
            'casing_compliance' => 'MEETS', 'measurements' => $points,
        ], $s['headers'])->assertStatus(201)->assertJsonPath('data.recommendation', 'REUSE')->json('data.id');
        $this->postJson("/api/v1/app/tire-used-inspections/{$id}/approve", ['warehouse_id' => $s['warehouse']->id], $s['headers'])->assertOk();

        return $id;
    }

    private function sell(array $s, Tire $tire, string $type, int $status)
    {
        return $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => $type, 'reason' => 'Test sale'], $s['headers'])->assertStatus($status);
    }

    public function test_operational_reuse_sale_requires_an_approved_reuse_inspection(): void
    {
        $s = $this->scenario();

        // Never inspected → refused.
        $fresh = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'TSR-NEW', 'current_status' => 'IN_STOCK']);
        $this->assertStringContainsString('approved used tire inspection', $this->sell($s, $fresh, 'SELL_FOR_OPERATIONAL_REUSE', 422)->json('message'));

        // Inspected and kept as REUSE → sold, and the sale records the inspection.
        $tire = $this->removedTire($s, 'TSR-REUSE');
        $inspectionId = $this->inspectReuse($s, $tire);
        $saleId = $this->sell($s, $tire, 'SELL_FOR_OPERATIONAL_REUSE', 201)->json('data.id');
        $sale = TireSale::query()->findOrFail($saleId);
        $this->assertSame($inspectionId, $sale->tire_used_inspection_id);
        $this->assertNull($sale->tire_scoring_result_id);
        $this->assertSame('SOLD', $tire->fresh()->current_status);

        // REUSE inspection, but the tire went back into service and was removed again → refused.
        $again = $this->removedTire($s, 'TSR-AGAIN');
        $this->inspectReuse($s, $again);
        $this->postJson("/api/v1/app/tires/{$again->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'RR', 'odometer' => 7000], $s['headers'])->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$again->id}/remove", ['removal_reason' => 'Damage', 'disposition' => 'REUSE', 'odometer' => 9000], $s['headers'])->assertSuccessful();
        $this->sell($s, $again->fresh(), 'SELL_FOR_OPERATIONAL_REUSE', 422);
    }

    public function test_scrap_and_casing_sales_and_sale_invariants_are_unchanged(): void
    {
        $s = $this->scenario();
        $scrap = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'TSR-SCRAP', 'current_status' => 'IN_STOCK']);
        $this->sell($s, $scrap, 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 201);
        $this->sell($s, $scrap->fresh(), 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 422); // already sold
        $this->postJson("/api/v1/app/tires/{$scrap->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FL'], $s['headers'])->assertStatus(422);

        $casing = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'TSR-CASING', 'current_status' => 'IN_STOCK']);
        $this->sell($s, $casing, 'SELL_AS_RETREADABLE_CASING', 201);

        $installed = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'TSR-INST', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$installed->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FL'], $s['headers'])->assertStatus(201);
        $this->sell($s, $installed, 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 422);

        [, $noSell] = $this->makeTenantUser($s['tenant'], ['tire.view', 'tire.manage']);
        $other = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'TSR-PERM', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$other->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'x'], $this->authHeaders($noSell))->assertStatus(403);
    }

    public function test_scoring_api_and_configuration_are_retired_but_history_is_kept(): void
    {
        $s = $this->scenario();
        $tire = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'TSR-HIST', 'current_status' => 'IN_STOCK']);

        // Legacy data from before the retirement: a published scoring configuration, a result, a sale.
        $service = app(ConfigurationService::class);
        $set = $service->findOrCreateSet($s['tenant']->id, 'TIRE_SCORING', 'RETREAD', 'TENANT', null, 'Retread Scoring');
        $legacyVersion = $service->publish($service->createDraft($set, ['bands' => []], null), null);
        $legacyDraft = $service->createDraft($set, ['bands' => []], null);
        $inspection = TireInspection::query()->create(['tenant_id' => $s['tenant']->id, 'tire_id' => $tire->id, 'tread_depth_mm' => 7, 'inspected_at' => now()]);
        $result = TireScoringResult::query()->create([
            'tenant_id' => $s['tenant']->id, 'tire_id' => $tire->id, 'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD',
            'configuration_version_id' => $legacyVersion->id, 'reference_tread_depth_mm' => 16, 'measured_tread_depth_mm' => 7,
            'spa_raw_percent' => 43.75, 'spa_normalized_score' => 40, 'classification' => 'BAD', 'critical_safety_fail' => false,
            'eligible_for_operational_reuse' => false, 'computed_at' => now(),
        ]);
        $sale = TireSale::query()->create(['tenant_id' => $s['tenant']->id, 'tire_id' => $tire->id, 'sell_type' => 'SELL_FOR_OPERATIONAL_REUSE', 'tire_scoring_result_id' => $result->id, 'reason' => 'Legacy sale', 'sold_at' => now()]);

        // The scoring endpoints no longer exist.
        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", ['tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD'], $s['headers'])->assertNotFound();
        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring/{$result->id}/finalize", [], $s['headers'])->assertNotFound();

        // The configuration type can no longer be listed for editing, created, changed or published…
        $this->getJson('/api/v1/app/configuration/sets?type=TIRE_SCORING', $s['headers'])->assertStatus(422);
        $this->postJson('/api/v1/app/configuration/versions', ['type' => 'TIRE_SCORING', 'code' => 'RETREAD', 'name' => 'X', 'payload' => ['bands' => []]], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/configuration/versions/{$legacyDraft->id}/publish", [], $s['headers'])->assertStatus(422);
        $this->putJson("/api/v1/app/configuration/versions/{$legacyDraft->id}", ['payload' => ['bands' => []]], $s['headers'])->assertStatus(422);

        // …but its history, the results and the sales that referenced them stay readable.
        $history = $this->getJson('/api/v1/app/configuration/history', $s['headers'])->assertOk()->json('data');
        $this->assertContains('TIRE_SCORING', array_column($history, 'type'));
        $this->assertSame($result->id, $sale->fresh()->scoringResult->id);
        $this->assertSame(1, $tire->scoringResults()->count());
        $this->getJson("/api/v1/app/tires/{$tire->id}", $s['headers'])->assertOk()->assertJsonMissingPath('data.scoringResults');
    }

    public function test_retired_permissions_are_not_offered_but_existing_assignments_are_kept(): void
    {
        $s = $this->scenario();
        foreach (['tire_scoring.calculate', 'tire_scoring_configuration.manage'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name], ['group' => explode('.', $name)[0], 'scope' => 'tenant', 'description' => $name]);
        }
        [$legacyHolder] = $this->makeTenantUser($s['tenant'], ['tire_scoring.calculate', 'tire.view']);

        $offered = array_column($this->getJson('/api/v1/app/permissions', $s['headers'])->assertOk()->json('data'), 'name');
        $this->assertNotContains('tire_scoring.calculate', $offered);
        $this->assertNotContains('tire_scoring_configuration.manage', $offered);
        $this->assertContains('tire_used_inspection.approve', $offered);
        $this->assertTrue(Permission::query()->where('name', 'tire_scoring.calculate')->exists(), 'permission rows are kept');
        $this->assertTrue(app(PermissionService::class)->userHasPermission($legacyHolder, 'tire_scoring.calculate', $s['tenant']->id), 'existing role assignments are kept');
    }
}
