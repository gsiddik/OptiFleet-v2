<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireScoringResult;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R2 (Production Readiness): TireService::retread()/repair() now run an
 * opt-in eligibility gate (TireDispositionEligibilityService) against the
 * tenant's ACTIVE (published) TIRE_SCORING configuration's
 * legal_restrictions / casing_eligibility / lifecycle_limits. Zero default
 * behavior change is the core invariant under test: with no published
 * config at all, every pre-existing retread/repair flow must behave
 * exactly as before R2.
 */
class TireDispositionEligibilityTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TDES-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'reference_tread_depth_mm' => 10]);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        return [$tenant, $vehicle, $product, $partner];
    }

    private function allPermissions(): array
    {
        return [
            'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap',
            'tire_retread.send', 'tire_retread.receive', 'tire_retread.inspect', 'tire_retread.approve',
            'tire_repair.send', 'tire_repair.receive', 'tire_repair.inspect', 'tire_repair.approve',
        ];
    }

    private function configPermissions(): array
    {
        return ['configuration.view', 'tire_scoring_configuration.manage', 'tire_scoring_configuration.publish'];
    }

    /** Installs and removes a tire with the given disposition, returning the tire fresh. */
    private function removeForCycle(Tire $tire, $vehicle, string $disposition, array $headers): Tire
    {
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Scheduled service', 'disposition' => $disposition], $headers)->assertStatus(201);

        return $tire->fresh();
    }

    private function basePolicy(): array
    {
        return [
            'legal_restrictions' => [],
            'casing_eligibility' => [
                'require_inspection' => false, 'prohibited_damage_categories' => [], 'max_previous_repairs' => null,
                'max_previous_retreads' => null, 'max_age_months' => null, 'max_mileage_km' => null,
                'exclude_if_critical_fail' => true, 'required_measurements' => [],
            ],
            'lifecycle_limits' => [
                'max_age_months' => null, 'max_mileage_km' => null, 'max_repair_count' => null, 'max_retread_count' => null,
                'missing_data_behavior' => 'BLOCK', 'boundary_inclusive' => true,
            ],
        ];
    }

    private function validBands(): array
    {
        return [
            ['min_percent' => 0, 'max_percent' => 100, 'classification' => 'GOOD', 'normalized_score' => 90, 'eligible_for_operational_reuse' => true],
        ];
    }

    /** Publishes an ACTIVE RETREAD/REPAIR TIRE_SCORING config with the given policy overrides, using distinct maker/checker users. Returns the published version id. */
    private function publishConfig(Tenant $tenant, string $code, array $policyOverrides = []): string
    {
        [, $makerToken] = $this->makeTenantUser($tenant, $this->configPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->configPermissions());

        $payload = ['bands' => $this->validBands()] + array_replace($this->basePolicy(), $policyOverrides);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => $code, 'name' => "Active {$code} policy", 'payload' => $payload,
        ], $this->authHeaders($makerToken))->assertStatus(201);

        $published = $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $this->authHeaders($checkerToken))
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        return $published->json('data.id');
    }

    public function test_retread_is_unaffected_when_no_active_config_exists(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOCFG', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
    }

    public function test_retread_is_blocked_when_max_retread_count_is_exceeded(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), $this->configPermissions()));
        $headers = $this->authHeaders($token);

        $this->publishConfig($tenant, 'RETREAD', [
            'lifecycle_limits' => [
                'max_age_months' => null, 'max_mileage_km' => null, 'max_repair_count' => null, 'max_retread_count' => 0,
                'missing_data_behavior' => 'BLOCK', 'boundary_inclusive' => true,
            ],
        ]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-MAXRETREAD', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    public function test_repair_is_blocked_when_casing_excludes_prior_critical_fail(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), $this->configPermissions()));
        $headers = $this->authHeaders($token);

        $versionId = $this->publishConfig($tenant, 'REPAIR', [
            'casing_eligibility' => [
                'require_inspection' => false, 'prohibited_damage_categories' => [], 'max_previous_repairs' => null,
                'max_previous_retreads' => null, 'max_age_months' => null, 'max_mileage_km' => null,
                'exclude_if_critical_fail' => true, 'required_measurements' => [],
            ],
        ]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-CRITFAIL', 'current_status' => 'IN_STOCK']);
        $inspection = TireInspection::query()->create([
            'tenant_id' => $tenant->id, 'tire_id' => $tire->id, 'tread_depth_mm' => 1, 'condition' => 'BAD', 'inspected_at' => now(),
        ]);
        TireScoringResult::query()->create([
            'tenant_id' => $tenant->id, 'tire_id' => $tire->id, 'tire_inspection_id' => $inspection->id, 'scoring_type' => 'REPAIR',
            'configuration_version_id' => $versionId,
            'reference_tread_depth_mm' => 10, 'measured_tread_depth_mm' => 1, 'spa_raw_percent' => 10,
            'spa_normalized_score' => 5, 'classification' => 'BAD', 'critical_safety_fail' => true,
            'critical_safety_reasons' => 'Sidewall bulge', 'eligible_for_operational_reuse' => false, 'computed_at' => now(),
        ]);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    public function test_repair_is_blocked_by_an_applicable_blocking_legal_restriction(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), $this->configPermissions()));
        $headers = $this->authHeaders($token);

        $this->publishConfig($tenant, 'REPAIR', [
            'legal_restrictions' => [[
                'code' => 'REG-1', 'name' => 'Repair moratorium', 'description' => 'No repairs permitted under current regulation',
                'jurisdiction' => 'NATIONAL', 'restriction_type' => 'REGULATORY', 'effective_date' => now()->subDay()->toDateString(),
                'expiry_date' => null, 'behavior' => 'BLOCK', 'applicable_process' => 'REPAIR',
            ]],
        ]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-LEGALBLOCK', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    public function test_repair_is_not_blocked_by_a_warn_only_legal_restriction(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), $this->configPermissions()));
        $headers = $this->authHeaders($token);

        $this->publishConfig($tenant, 'REPAIR', [
            'legal_restrictions' => [[
                'code' => 'ADV-1', 'name' => 'Advisory notice', 'description' => 'Informational only',
                'jurisdiction' => 'NATIONAL', 'restriction_type' => 'ADVISORY', 'effective_date' => now()->subDay()->toDateString(),
                'expiry_date' => null, 'behavior' => 'WARN', 'applicable_process' => 'REPAIR',
            ]],
        ]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-LEGALWARN', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $partner->id], $headers)->assertStatus(201);
    }

    public function test_retread_is_blocked_when_lifecycle_missing_data_behavior_is_block_and_mileage_is_unknown(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), $this->configPermissions()));
        $headers = $this->authHeaders($token);

        $this->publishConfig($tenant, 'RETREAD', [
            'lifecycle_limits' => [
                'max_age_months' => null, 'max_mileage_km' => 50000, 'max_repair_count' => null, 'max_retread_count' => null,
                'missing_data_behavior' => 'BLOCK', 'boundary_inclusive' => true,
            ],
        ]);

        // install()/remove() do not record an installation_odometer, so lifetime mileage is indeterminate (null) — fail-closed.
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-MISSINGDATA', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    public function test_retread_proceeds_when_lifecycle_missing_data_behavior_is_warn(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), $this->configPermissions()));
        $headers = $this->authHeaders($token);

        $this->publishConfig($tenant, 'RETREAD', [
            'lifecycle_limits' => [
                'max_age_months' => null, 'max_mileage_km' => 50000, 'max_repair_count' => null, 'max_retread_count' => null,
                'missing_data_behavior' => 'WARN', 'boundary_inclusive' => true,
            ],
        ]);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-WARNONLY', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
    }
}
