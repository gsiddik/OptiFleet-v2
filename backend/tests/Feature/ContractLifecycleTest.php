<?php

namespace Tests\Feature;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Services\ContractService;
use Tests\TestCase;

class ContractLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    public function test_contract_creation_resolves_and_freezes_pricing(): void
    {
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BASIC');

        $item = $contract->items->first();
        $this->assertSame('2000000.00', (string) $item->unit_price);
        $this->assertSame('DRAFT', $contract->status);
    }

    public function test_only_authorized_platform_role_can_approve(): void
    {
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BASIC');
        app(ContractService::class)->submitForApproval($contract);

        [, $unauthorizedToken] = $this->makePlatformUser(['contract.view']); // no contract.approve

        $response = $this->postJson("/api/v1/platform/contracts/{$contract->id}/approve", [], $this->authHeaders($unauthorizedToken));
        $response->assertStatus(403);

        [, $authorizedToken] = $this->makePlatformUser(['contract.approve']);
        $response = $this->postJson("/api/v1/platform/contracts/{$contract->id}/approve", [], $this->authHeaders($authorizedToken));
        $response->assertOk();
    }

    public function test_approved_contract_creates_subscription_and_invoice(): void
    {
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]);
        $contract = $this->approveContract($contract);

        $this->assertSame('ACTIVE', $contract->status);
        $subscription = $contract->subscription;
        $this->assertNotNull($subscription);
        $this->assertSame('ACTIVE', $subscription->status);
        $this->assertSame(1, $subscription->invoices()->count());
    }

    public function test_draft_contract_cannot_be_edited_once_approved(): void
    {
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BASIC');
        $contract = $this->approveContract($contract);

        $this->expectException(\App\Domain\Contract\Services\ContractException::class);
        app(ContractService::class)->addItem($contract, [
            'product_type' => 'ADD_ON',
            'description' => 'Should fail',
            'unit_price' => 100,
            'billing_frequency' => 'MONTHLY',
            'valid_from' => now()->toDateString(),
        ]);
    }

    public function test_cannot_submit_contract_with_no_items(): void
    {
        $tenant = $this->makeTenant();
        $contract = app(ContractService::class)->createDraft($tenant->id, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'billing_cycle' => 'MONTHLY',
        ], []);

        $this->expectException(\App\Domain\Contract\Services\ContractException::class);
        app(ContractService::class)->submitForApproval($contract);
    }

    public function test_module_add_amendment_provisions_entitlement(): void
    {
        $this->makePricing('MODULE', 'HISTORY', '500000');

        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]);
        $contract = $this->approveContract($contract);

        [, $token] = $this->makePlatformUser(['contract.amend', 'contract.approve']);

        $amendResp = $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments", [
            'reason' => 'Add reporting history module',
            'effective_date' => now()->toDateString(),
        ], $this->authHeaders($token));
        $amendResp->assertStatus(201);
        $amendmentId = $amendResp->json('data.id');

        $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments/{$amendmentId}/items", [
            'product_type' => 'MODULE',
            'product_reference' => 'HISTORY',
            'description' => 'History module add-on',
            'billing_frequency' => 'MONTHLY',
        ], $this->authHeaders($token))->assertStatus(201);

        $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments/{$amendmentId}/submit", [], $this->authHeaders($token))->assertOk();
        $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments/{$amendmentId}/approve", [], $this->authHeaders($token))->assertOk();

        $this->assertTrue(
            app(\App\Domain\Entitlement\Services\EntitlementService::class)->tenantHasModule($tenant->id, 'HISTORY')
        );
    }

    public function test_removing_module_with_active_dependent_is_rejected(): void
    {
        $this->makePricing('MODULE', 'VEHICLE', '100000');
        $this->makePricing('MODULE', 'MAINTENANCE', '150000');
        $this->makePricing('MODULE', 'WORKSHOP', '150000');
        $this->makePricing('MODULE', 'WORK_ORDER', '400000');

        $tenant = $this->makeTenant();
        $contract = app(ContractService::class)->createDraft($tenant->id, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'billing_cycle' => 'MONTHLY',
            'activation_requires_payment' => false,
        ], array_map(fn ($code) => [
            'product_type' => 'MODULE',
            'product_reference' => $code,
            'description' => $code,
            'billing_frequency' => 'MONTHLY',
            'valid_from' => now()->toDateString(),
        ], ['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'])); // WORK_ORDER depends on the other three

        $contract = $this->approveContract($contract);
        $vehicleItem = $contract->items()->where('product_reference', 'VEHICLE')->first();

        $amendment = app(\App\Domain\Contract\Services\AmendmentService::class)
            ->createDraft($contract, 'Remove vehicle', now()->toDateString(), $this->makePlatformUser()[0]->id);

        $this->expectException(\App\Domain\Contract\Services\AmendmentException::class);
        app(\App\Domain\Contract\Services\AmendmentService::class)->removeItem($amendment, $vehicleItem->id);
    }

    public function test_renewal_creates_new_contract_preserving_original(): void
    {
        $tenant = $this->makeTenant();
        $original = $this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]);
        $original = $this->approveContract($original);
        $originalStatus = $original->status;
        $originalNumber = $original->contract_number;

        $renewal = app(\App\Domain\Contract\Services\RenewalService::class)->createRenewalDraft($original, [
            'end_date' => $original->end_date->copy()->addYear()->toDateString(),
        ], [
            [
                'product_type' => 'BUNDLE',
                'product_reference' => 'BASIC',
                'description' => 'BASIC renewal',
                'billing_frequency' => 'MONTHLY',
                'valid_from' => $original->end_date->copy()->addDay()->toDateString(),
            ],
        ]);

        $this->assertNotSame($originalNumber, $renewal->contract_number);
        $this->assertSame($original->id, $renewal->renewed_from_contract_id);
        $this->assertSame($originalStatus, $original->fresh()->status); // untouched
        $this->assertSame('DRAFT', $renewal->status);
    }
}
