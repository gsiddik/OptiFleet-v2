<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CrossTenantCommercialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    public function test_tenant_cannot_view_another_tenants_invoice(): void
    {
        $tenantA = $this->makeTenant(['code' => 'CT-A1']);
        $tenantB = $this->makeTenant(['code' => 'CT-B1']);

        $contractA = $this->approveContract($this->makeContractDraft($tenantA, 'BASIC', ['activation_requires_payment' => false]));
        $invoiceA = $contractA->subscription->invoices()->first();

        [, $tokenB] = $this->makeTenantUser($tenantB, ['account.invoice.view', 'account.invoice.download']);

        $this->getJson("/api/v1/app/account/invoices/{$invoiceA->id}", $this->authHeaders($tokenB))->assertStatus(404);
        $this->getJson("/api/v1/app/account/invoices/{$invoiceA->id}/pdf", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_tenant_cannot_submit_payment_against_another_tenants_invoice(): void
    {
        $tenantA = $this->makeTenant(['code' => 'CT-A2']);
        $tenantB = $this->makeTenant(['code' => 'CT-B2']);

        $contractA = $this->approveContract($this->makeContractDraft($tenantA, 'BASIC', ['activation_requires_payment' => false]));
        $invoiceA = $contractA->subscription->invoices()->first();

        [, $tokenB] = $this->makeTenantUser($tenantB, ['account.payment.submit']);

        $response = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoiceA->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoiceA->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tokenB));

        $response->assertStatus(404);
    }

    public function test_tenant_cannot_view_another_tenants_payment_or_proof(): void
    {
        $tenantA = $this->makeTenant(['code' => 'CT-A3']);
        $tenantB = $this->makeTenant(['code' => 'CT-B3']);

        $contractA = $this->approveContract($this->makeContractDraft($tenantA, 'BASIC', ['activation_requires_payment' => false]));
        $invoiceA = $contractA->subscription->invoices()->first();

        [, $tokenA] = $this->makeTenantUser($tenantA, ['account.payment.submit', 'account.payment.view']);
        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoiceA->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoiceA->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tokenA))->json('data.id');

        $file = UploadedFile::fake()->image('proof.jpg');
        $proofId = $this->postJson("/api/v1/app/account/payments/{$paymentId}/proof", ['file' => $file], $this->authHeaders($tokenA))->json('data.id');

        [, $tokenB] = $this->makeTenantUser($tenantB, ['account.payment.view']);
        $this->getJson("/api/v1/app/account/payments/{$paymentId}", $this->authHeaders($tokenB))->assertStatus(404);
        $this->getJson("/api/v1/app/account/payments/{$paymentId}/proofs/{$proofId}", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_tenant_cannot_view_another_tenants_contract(): void
    {
        $tenantA = $this->makeTenant(['code' => 'CT-A4']);
        $tenantB = $this->makeTenant(['code' => 'CT-B4']);

        $this->approveContract($this->makeContractDraft($tenantA, 'BASIC', ['activation_requires_payment' => false]));

        [, $tokenB] = $this->makeTenantUser($tenantB, ['account.contract.view']);
        $response = $this->getJson('/api/v1/app/account/contract', $this->authHeaders($tokenB));

        $response->assertOk();
        $this->assertNull($response->json('data')); // tenant B has no contract of its own
    }

    public function test_tenant_cannot_directly_modify_pricing_or_contracts(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makeTenantUser($tenant, ['account.subscription.view']);

        // No tenant-scope routes exist for pricing/contract mutation at all —
        // confirm the platform-only endpoints reject a tenant token outright.
        $this->postJson('/api/v1/platform/contracts', [], $this->authHeaders($token))->assertStatus(403);
        $this->postJson('/api/v1/platform/pricing', [], $this->authHeaders($token))->assertStatus(403);
        $this->postJson('/api/v1/platform/bundles', [], $this->authHeaders($token))->assertStatus(403);
    }
}
