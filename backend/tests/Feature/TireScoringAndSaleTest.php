<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireScoringResult;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase F — Tire Scoring and Classification: G-31 structured scoring
 * (SPA raw/normalized, KA, KTS/KTN, classification, KF as supporting
 * information only), BD-1 (critical-fail gate overrides KF), BD-2
 * (separate versioned Repair/Retread configs), BD-3 (mm-based tread
 * depth, Product-sourced reference, reject on missing/zero/invalid),
 * BD-4 (maker-checker on finalize), BD-5 (three-way sell split, unsafe
 * reuse blocked), BD-8 (platform default + tenant override, tenant
 * cannot weaken safety flags, finalized results immutable).
 */
class TireScoringAndSaleTest extends TestCase
{
    private function setUpScenario(?float $referenceTreadDepth = 8.0): array
    {
        $tenant = $this->makeTenant(['code' => 'TSC-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'reference_tread_depth_mm' => $referenceTreadDepth]);

        return [$tenant, $vehicle, $product];
    }

    private function allPermissions(): array
    {
        return [
            'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap', 'tire.sell',
            'tire_retread.send', 'tire_retread.receive', 'tire_retread.inspect', 'tire_retread.approve',
            'tire_scoring.calculate', 'tire_scoring.finalize',
            'configuration.view', 'tire_scoring_configuration.manage', 'tire_scoring_configuration.publish',
        ];
    }

    /** Standard 4-band RETREAD config: [0,20)=CRITICAL(fail,not eligible) [20,50)=BAD(not eligible) [50,80)=FAIR(eligible) [80,100]=GOOD(eligible), KF = 0.7*spa + 0.3*ka, requires_ka. */
    private function publishStandardConfig(array $headers, string $code = 'RETREAD', array $overrides = []): string
    {
        $payload = array_replace([
            'requires_ka' => true,
            'kf_weights' => ['spa_normalized' => 0.7, 'ka' => 0.3],
            'bands' => [
                ['min_percent' => 0, 'max_percent' => 20, 'classification' => 'CRITICAL', 'normalized_score' => 0, 'eligible_for_operational_reuse' => false, 'is_critical_fail' => true],
                ['min_percent' => 20, 'max_percent' => 50, 'classification' => 'BAD', 'normalized_score' => 40, 'eligible_for_operational_reuse' => false],
                ['min_percent' => 50, 'max_percent' => 80, 'classification' => 'FAIR', 'normalized_score' => 70, 'eligible_for_operational_reuse' => true],
                ['min_percent' => 80, 'max_percent' => 100, 'classification' => 'GOOD', 'normalized_score' => 100, 'eligible_for_operational_reuse' => true],
            ],
        ], $overrides);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => $code, 'name' => ucfirst(strtolower($code)).' Scoring (test fixture)', 'payload' => $payload,
        ], $headers)->assertStatus(201);
        $versionId = $draft->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$versionId}/publish", [], $headers)->assertOk();

        return $versionId;
    }

    private function makeInspection(Tire $tire, float $treadDepthMm, array $headers): TireInspection
    {
        $response = $this->postJson("/api/v1/app/tires/{$tire->id}/inspect", ['tread_depth_mm' => $treadDepthMm], $headers)->assertStatus(201);

        return TireInspection::query()->findOrFail($response->json('data.id'));
    }

    // ---- BD-3: reject on missing/zero/invalid/incompatible reference or measurement --------

    public function test_calculation_rejected_when_product_has_no_reference_tread_depth(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: null);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOREF', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $headers)->assertStatus(422);
    }

    public function test_calculation_rejected_when_reference_tread_depth_is_zero(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: null);
        $product->forceFill(['reference_tread_depth_mm' => 0])->save();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-ZEROREF', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $headers)->assertStatus(422);
    }

    public function test_calculation_rejected_when_product_is_not_a_tire_product(): void
    {
        [$tenant] = $this->setUpScenario();
        $nonTireProduct = $this->makeProduct($tenant, null, null, ['product_type' => 'SPARE_PART', 'reference_tread_depth_mm' => 8]);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $nonTireProduct->id, 'serial_number' => 'SN-BADPROD', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $headers)->assertStatus(422);
    }

    public function test_calculation_rejected_when_no_published_configuration_exists(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        // No configuration published this time — framework present, nothing activated.

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOCONFIG', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $headers)->assertStatus(422);
    }

    public function test_calculation_rejected_when_config_requires_ka_but_none_supplied(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOKA', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false,
        ], $headers)->assertStatus(422);
    }

    // ---- Core calculation: SPA raw/normalized, classification, precision -------------------

    public function test_calculation_produces_expected_spa_and_classification_for_a_fair_band(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-FAIR', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers); // 6/8 = 75% -> FAIR band

        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 90,
        ], $headers)->assertStatus(201);

        $this->assertEquals(75.0, (float) $result->json('data.spa_raw_percent'));
        $this->assertEquals(70.0, (float) $result->json('data.spa_normalized_score'));
        $this->assertSame('FAIR', $result->json('data.classification'));
        $this->assertFalse($result->json('data.critical_safety_fail'));
        $this->assertTrue($result->json('data.eligible_for_operational_reuse'));
        // KF = 0.7*70 + 0.3*90 = 49 + 27 = 76.00
        $this->assertEquals(76.0, (float) $result->json('data.kf_score'));
    }

    public function test_calculation_rounds_repeating_decimal_to_two_places(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 3.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-ROUND', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 1.0, $headers); // 1/3 = 33.333...%

        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 50,
        ], $headers)->assertStatus(201);

        $this->assertEquals(33.33, (float) $result->json('data.spa_raw_percent'));
    }

    public function test_measured_tread_depth_at_or_above_reference_matches_top_band(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-TOPBAND', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 8.0, $headers); // 100%

        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 100,
        ], $headers)->assertStatus(201);

        $this->assertSame('GOOD', $result->json('data.classification'));
    }

    // ---- BD-1/BD-6: critical safety gate overrides everything, unconditionally -------------

    public function test_inspector_flagged_critical_fail_forces_ineligibility_regardless_of_band(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-INSPFAIL', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 8.0, $headers); // would be GOOD/eligible band

        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD',
            'critical_safety_fail' => true, 'critical_safety_reasons' => 'Sidewall cracking observed', 'ka_score' => 100,
        ], $headers)->assertStatus(201);

        $this->assertSame('GOOD', $result->json('data.classification'));
        $this->assertTrue($result->json('data.critical_safety_fail'));
        $this->assertFalse($result->json('data.eligible_for_operational_reuse'));
    }

    public function test_critical_safety_fail_requires_a_reason(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOREASON', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 8.0, $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => true, 'ka_score' => 100,
        ], $headers)->assertStatus(422);
    }

    public function test_band_marked_critical_fail_forces_critical_even_when_inspector_says_safe(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-BANDFAIL', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 1.0, $headers); // 1/8=12.5% -> CRITICAL band, is_critical_fail=true

        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 10,
        ], $headers)->assertStatus(201);

        $this->assertTrue($result->json('data.critical_safety_fail'));
        $this->assertFalse($result->json('data.eligible_for_operational_reuse'));
    }

    // ---- BD-4/BD-8: finalize is maker-checker and immutable ---------------------------------

    public function test_finalize_requires_a_different_actor_than_the_computer(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELFFIN', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);
        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring/{$result->json('data.id')}/finalize", [], $headers)->assertStatus(422);
    }

    public function test_finalize_by_a_different_actor_succeeds_and_is_immutable(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $computerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        [, $finalizerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $computerHeaders = $this->authHeaders($computerToken);
        $finalizerHeaders = $this->authHeaders($finalizerToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-FINAL', 'current_status' => 'IN_STOCK']);
        $this->publishStandardConfig($computerHeaders);
        $inspection = $this->makeInspection($tire, 6.0, $computerHeaders);
        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $computerHeaders)->assertStatus(201);
        $resultId = $result->json('data.id');

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring/{$resultId}/finalize", [], $finalizerHeaders)->assertOk();

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring/{$resultId}/finalize", [], $finalizerHeaders)->assertStatus(422);
        $this->assertNotNull(TireScoringResult::query()->findOrFail($resultId)->finalized_at);
    }

    // ---- Configuration validator: shape rules and BD-8 non-weakening ------------------------

    public function test_publish_rejects_bands_with_a_gap(): void
    {
        [$tenant] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'RETREAD', 'name' => 'Gappy', 'payload' => [
                'bands' => [
                    ['min_percent' => 0, 'max_percent' => 40, 'classification' => 'BAD', 'normalized_score' => 0, 'eligible_for_operational_reuse' => false],
                    ['min_percent' => 50, 'max_percent' => 100, 'classification' => 'GOOD', 'normalized_score' => 100, 'eligible_for_operational_reuse' => true],
                ],
            ],
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_publish_rejects_kf_weight_on_ka_when_ka_not_required(): void
    {
        [$tenant] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Bad KF', 'payload' => [
                'requires_ka' => false,
                'kf_weights' => ['ka' => 0.5],
                'bands' => [['min_percent' => 0, 'max_percent' => 100, 'classification' => 'OK', 'normalized_score' => 50, 'eligible_for_operational_reuse' => true]],
            ],
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_repair_config_does_not_require_ka_or_kf_by_default(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers, 'REPAIR', ['requires_ka' => false, 'kf_weights' => null]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-REPAIRNOKF', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $headers);

        $result = $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'REPAIR', 'critical_safety_fail' => false,
        ], $headers)->assertStatus(201);

        $this->assertNull($result->json('data.kf_score'));
    }

    public function test_tenant_override_cannot_weaken_a_platform_default_safety_flag(): void
    {
        // The platform default is created directly (bypassing the tenant-scoped API), matching
        // how a real platform-level TIRE_SCORING default would exist independently of any tenant.
        $platformSet = ConfigurationSet::query()->create([
            'tenant_id' => null, 'type' => 'TIRE_SCORING', 'code' => 'RETREAD',
            'scope_type' => 'TENANT', 'scope_resource_id' => null, 'name' => 'Platform Default Retread Scoring', 'is_system' => true,
        ]);
        $platformVersion = $platformSet->versions()->create([
            'version_number' => 1, 'status' => 'PUBLISHED', 'published_at' => now(),
            'payload' => [
                'bands' => [
                    ['min_percent' => 0, 'max_percent' => 20, 'classification' => 'CRITICAL', 'normalized_score' => 0, 'eligible_for_operational_reuse' => false, 'is_critical_fail' => true],
                    ['min_percent' => 20, 'max_percent' => 100, 'classification' => 'OK', 'normalized_score' => 80, 'eligible_for_operational_reuse' => true],
                ],
            ],
        ]);

        [$tenant] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'RETREAD', 'name' => 'Weakened Tenant Override', 'payload' => [
                'bands' => [
                    // Same classification label as the platform's critical band, but tries to mark it non-critical.
                    ['min_percent' => 0, 'max_percent' => 20, 'classification' => 'CRITICAL', 'normalized_score' => 10, 'eligible_for_operational_reuse' => false, 'is_critical_fail' => false],
                    ['min_percent' => 20, 'max_percent' => 100, 'classification' => 'OK', 'normalized_score' => 80, 'eligible_for_operational_reuse' => true],
                ],
            ],
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    // ---- Integration with Phase E approval: linked critical-fail scoring blocks return-to-service ----

    public function test_critical_fail_scoring_result_linked_to_a_retread_cycle_blocks_return_to_service(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-CYCLEFAIL', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Worn', 'disposition' => 'RETREAD'], $headers)->assertStatus(201);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();
        $inspection = $this->makeInspection($tire, 1.0, $headers); // CRITICAL band -> band-level critical fail
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'SAFE'], $headers)->assertOk();

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false,
            'ka_score' => 10, 'tire_retread_id' => $retreadId,
        ], $headers)->assertStatus(201);

        [, $checkerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $checkerHeaders = $this->authHeaders($checkerToken);
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Attempting despite critical score',
        ], $checkerHeaders)->assertStatus(422);
    }

    // ---- BD-5: three-way sell split, unsafe operational reuse blocked -----------------------

    public function test_sell_for_operational_reuse_rejected_without_any_scoring_result(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLNOSCORE', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_FOR_OPERATIONAL_REUSE', 'reason' => 'Selling spare'], $headers)->assertStatus(422);
    }

    public function test_sell_for_operational_reuse_rejected_when_critical_safety_fail(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLFAIL', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 8.0, $headers);
        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD',
            'critical_safety_fail' => true, 'critical_safety_reasons' => 'Cracked sidewall', 'ka_score' => 100,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_FOR_OPERATIONAL_REUSE', 'reason' => 'Selling anyway'], $headers)->assertStatus(422);
    }

    public function test_sell_for_operational_reuse_rejected_when_classification_ineligible(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLBAD', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 3.0, $headers); // 3/8=37.5% -> BAD, not eligible
        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 50,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_FOR_OPERATIONAL_REUSE', 'reason' => 'Trying to sell BAD tire'], $headers)->assertStatus(422);
    }

    public function test_sell_for_operational_reuse_succeeds_when_eligible(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $this->publishStandardConfig($headers);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLGOOD', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 7.0, $headers); // 87.5% -> GOOD, eligible
        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 95,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_FOR_OPERATIONAL_REUSE', 'reason' => 'Fit for reuse'], $headers)->assertStatus(201);

        $tire->refresh();
        $this->assertSame('SOLD', $tire->current_status);
    }

    public function test_sell_as_scrap_or_casing_requires_no_scoring_result(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tireA = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLSCRAP', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tireA->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'End of life'], $headers)->assertStatus(201);

        $tireB = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLCASING', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tireB->id}/sell", ['sell_type' => 'SELL_AS_RETREADABLE_CASING', 'reason' => 'Sound casing'], $headers)->assertStatus(201);
    }

    public function test_already_sold_tire_cannot_be_sold_again(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-RESELL', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'First sale'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'Second sale'], $headers)->assertStatus(422);
    }

    public function test_sold_tire_cannot_be_installed(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SOLDINSTALL', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'Sold'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(422);
    }

    public function test_installed_tire_cannot_be_sold(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-INSTSELL', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'Trying while installed'], $headers)->assertStatus(422);
    }

    // ---- Permission gating -------------------------------------------------------------------

    public function test_scoring_calculation_requires_permission(): void
    {
        [$tenant, , $product] = $this->setUpScenario(referenceTreadDepth: 8.0);
        [, $fullToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        [, $noPermToken] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage', 'tire.inspect']);
        $fullHeaders = $this->authHeaders($fullToken);
        $noPermHeaders = $this->authHeaders($noPermToken);
        $this->publishStandardConfig($fullHeaders);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOPERM', 'current_status' => 'IN_STOCK']);
        $inspection = $this->makeInspection($tire, 6.0, $fullHeaders);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scoring", [
            'tire_inspection_id' => $inspection->id, 'scoring_type' => 'RETREAD', 'critical_safety_fail' => false, 'ka_score' => 80,
        ], $noPermHeaders)->assertStatus(403);
    }

    public function test_sell_requires_permission(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $noPermToken] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage']);
        $noPermHeaders = $this->authHeaders($noPermToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELLPERM', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/sell", ['sell_type' => 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL', 'reason' => 'x'], $noPermHeaders)->assertStatus(403);
    }
}
