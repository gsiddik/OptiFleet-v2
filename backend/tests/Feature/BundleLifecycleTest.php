<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use Tests\TestCase;

class BundleLifecycleTest extends TestCase
{
    public function test_deactivate_and_reactivate_require_permission_and_are_audited(): void
    {
        $bundle = $this->makeBundle('BLIFE1', ['CORE']);

        [, $noPermToken] = $this->makePlatformUser(['bundle.view']);
        $this->postJson("/api/v1/platform/bundles/{$bundle->id}/deactivate", [], $this->authHeaders($noPermToken))
            ->assertStatus(403);

        [, $token] = $this->makePlatformUser(['bundle.view', 'bundle.deactivate', 'bundle.activate']);

        $this->postJson("/api/v1/platform/bundles/{$bundle->id}/deactivate", [], $this->authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(
            AuditLog::query()->where('resource_type', 'Bundle')->where('resource_id', $bundle->id)->where('action', 'updated')->exists()
        );

        $this->postJson("/api/v1/platform/bundles/{$bundle->id}/reactivate", [], $this->authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_soft_deleted_bundle_is_excluded_from_default_list_but_keeps_historical_contract_reference(): void
    {
        $bundle = $this->makeBundle('BLIFE2', ['CORE']);
        $this->makePricing('BUNDLE', 'BLIFE2', '1000000');
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BLIFE2');
        $historicalBundleVersionId = $contract->items->first()->bundle_version_id;

        [, $token] = $this->makePlatformUser(['bundle.view', 'bundle.delete']);

        $this->deleteJson("/api/v1/platform/bundles/{$bundle->id}", [], $this->authHeaders($token))
            ->assertOk();

        $this->assertSoftDeleted('bundles', ['id' => $bundle->id]);

        $listResponse = $this->getJson('/api/v1/platform/bundles', $this->authHeaders($token));
        $listResponse->assertOk();
        $this->assertFalse(collect($listResponse->json('data'))->contains('id', $bundle->id));

        // The historical contract item's frozen bundle_version_id must
        // remain resolvable even though the bundle itself is soft-deleted.
        $contract->refresh();
        $this->assertSame($historicalBundleVersionId, $contract->items->first()->bundle_version_id);
        $this->assertNotNull(\App\Domain\ProductCatalog\Models\BundleVersion::query()->find($historicalBundleVersionId));
    }

    public function test_inactive_or_deleted_bundle_cannot_be_selected_for_a_new_contract(): void
    {
        $bundle = $this->makeBundle('BLIFE3', ['CORE']);
        $this->makePricing('BUNDLE', 'BLIFE3', '1000000');
        [, $token] = $this->makePlatformUser(['bundle.deactivate']);
        $this->postJson("/api/v1/platform/bundles/{$bundle->id}/deactivate", [], $this->authHeaders($token))->assertOk();

        $tenant = $this->makeTenant();
        $this->expectException(\App\Domain\Contract\Services\ContractException::class);
        $this->makeContractDraft($tenant, 'BLIFE3');
    }

    public function test_module_dependency_auto_add_is_reflected_in_sync_response(): void
    {
        $bundle = \App\Domain\ProductCatalog\Models\Bundle::query()->create(['code' => 'BLIFE4', 'name' => 'BLIFE4', 'status' => 'DRAFT', 'is_active' => true]);
        $workOrder = \App\Domain\ProductCatalog\Models\Module::query()->where('code', 'WORK_ORDER')->first();

        [, $token] = $this->makePlatformUser(['bundle.update']);
        $response = $this->putJson("/api/v1/platform/bundles/{$bundle->id}/modules", ['module_ids' => [$workOrder->id]], $this->authHeaders($token));

        $response->assertOk();
        $autoAddedCodes = collect($response->json('data.auto_added'))->pluck('module.code')->sort()->values()->all();
        $this->assertSame(['MAINTENANCE', 'VEHICLE', 'WORKSHOP'], $autoAddedCodes);
    }
}
