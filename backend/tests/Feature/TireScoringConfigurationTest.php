<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R2 (Production Readiness — supersedes part of BD-6's "needs specialist
 * input"): legal_restrictions, casing_eligibility, and lifecycle_limits are
 * now required, explicit configuration categories for a TIRE_SCORING
 * configuration to be activatable (= published). Vehicle/axle-suitability
 * and a dedicated Tire-specialist/safety-department approval step are
 * explicitly and permanently out of scope — never required here.
 */
class TireScoringConfigurationTest extends TestCase
{
    private function permissions(): array
    {
        return ['configuration.view', 'tire_scoring_configuration.manage', 'tire_scoring_configuration.publish'];
    }

    private function validBands(): array
    {
        return [
            ['min_percent' => 0, 'max_percent' => 50, 'classification' => 'BAD', 'normalized_score' => 20, 'eligible_for_operational_reuse' => false],
            ['min_percent' => 50, 'max_percent' => 100, 'classification' => 'GOOD', 'normalized_score' => 90, 'eligible_for_operational_reuse' => true],
        ];
    }

    private function validDispositionPolicy(): array
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

    public function test_publish_rejects_missing_legal_restrictions_key(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $payload = ['bands' => $this->validBands()] + array_diff_key($this->validDispositionPolicy(), ['legal_restrictions' => true]);
        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Missing legal_restrictions', 'payload' => $payload,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_publish_rejects_missing_casing_eligibility_key(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $payload = ['bands' => $this->validBands()] + array_diff_key($this->validDispositionPolicy(), ['casing_eligibility' => true]);
        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Missing casing_eligibility', 'payload' => $payload,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_publish_rejects_missing_lifecycle_limits_key(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $payload = ['bands' => $this->validBands()] + array_diff_key($this->validDispositionPolicy(), ['lifecycle_limits' => true]);
        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Missing lifecycle_limits', 'payload' => $payload,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_publish_rejects_lifecycle_limits_with_invalid_missing_data_behavior(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $policy = $this->validDispositionPolicy();
        $policy['lifecycle_limits']['missing_data_behavior'] = 'IGNORE';
        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Bad behavior', 'payload' => ['bands' => $this->validBands()] + $policy,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_publish_rejects_legal_restriction_missing_required_field(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $policy = $this->validDispositionPolicy();
        $policy['legal_restrictions'] = [['code' => 'X', 'name' => 'Missing behavior']];
        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Incomplete restriction', 'payload' => ['bands' => $this->validBands()] + $policy,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_publish_accepts_complete_valid_configuration_with_a_different_publisher(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $makerToken] = $this->makeTenantUser($tenant, $this->permissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->permissions());

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Complete config',
            'payload' => ['bands' => $this->validBands()] + $this->validDispositionPolicy(),
        ], $this->authHeaders($makerToken))->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $this->authHeaders($checkerToken))
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_maker_cannot_also_publish_their_own_draft(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'Self-publish attempt',
            'payload' => ['bands' => $this->validBands()] + $this->validDispositionPolicy(),
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)->assertStatus(422);
    }

    public function test_dry_run_preview_computes_without_persisting_and_reports_missing_inputs(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $payload = ['bands' => $this->validBands()] + $this->validDispositionPolicy();

        $result = $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'payload' => $payload,
            'reference_tread_depth_mm' => 8.0, 'measured_tread_depth_mm' => 6.0,
        ], $headers)->assertOk();

        $this->assertSame('GOOD', $result->json('data.draft_result.classification'));
        $this->assertEquals(75.0, $result->json('data.draft_result.spa_raw_percent'));
        $this->assertNull($result->json('data.active_configuration_version_id'));

        $missingInputs = $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'payload' => $payload,
            'measured_tread_depth_mm' => 6.0,
        ], $headers)->assertOk();
        $this->assertContains('reference_tread_depth_mm', $missingInputs->json('data.draft_result.missing_inputs'));

        $this->assertDatabaseCount('tire_scoring_results', 0);
    }

    public function test_dry_run_preview_rejects_invalid_draft_payload(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR',
            'payload' => ['bands' => $this->validBands()], // missing legal_restrictions/casing_eligibility/lifecycle_limits
            'reference_tread_depth_mm' => 8.0, 'measured_tread_depth_mm' => 6.0,
        ], $headers)->assertStatus(422);
    }

    public function test_dry_run_preview_compares_against_currently_active_version(): void
    {
        $tenant = $this->makeTenant(['code' => 'TSCFG-'.Str::random(4)]);
        [, $makerToken] = $this->makeTenantUser($tenant, $this->permissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->permissions());

        $activePayload = ['bands' => $this->validBands()] + $this->validDispositionPolicy();
        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'name' => 'v1', 'payload' => $activePayload,
        ], $this->authHeaders($makerToken))->assertStatus(201);
        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $this->authHeaders($checkerToken))->assertOk();

        $newDraftPayload = $activePayload;
        $newDraftPayload['bands'] = [
            ['min_percent' => 0, 'max_percent' => 100, 'classification' => 'ALWAYS_GOOD', 'normalized_score' => 100, 'eligible_for_operational_reuse' => true],
        ];

        $result = $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'TIRE_SCORING', 'code' => 'REPAIR', 'payload' => $newDraftPayload,
            'reference_tread_depth_mm' => 8.0, 'measured_tread_depth_mm' => 6.0,
        ], $this->authHeaders($makerToken))->assertOk();

        $this->assertNotNull($result->json('data.active_configuration_version_id'));
        $this->assertSame('ALWAYS_GOOD', $result->json('data.draft_result.classification'));
        $this->assertSame('GOOD', $result->json('data.active_result.classification'));
        $this->assertTrue($result->json('data.changed_from_active'));
    }
}
