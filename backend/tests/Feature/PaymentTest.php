<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    private function setupTenantWithInvoice(): array
    {
        $tenant = $this->makeTenant();
        $contract = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => true]));
        $invoice = $contract->subscription->invoices()->first();
        [$user, $token] = $this->makeTenantUser($tenant, ['account.payment.submit', 'account.payment.view', 'account.subscription.view']);

        return [$tenant, $contract, $invoice, $user, $token];
    }

    public function test_tenant_can_submit_payment_for_own_invoice(): void
    {
        [, , $invoice, , $token] = $this->setupTenantWithInvoice();

        $response = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($token));

        $response->assertStatus(201)->assertJsonPath('data.status', 'SUBMITTED');
    }

    public function test_upload_rejects_invalid_file_type(): void
    {
        [, , $invoice, , $token] = $this->setupTenantWithInvoice();

        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($token))->json('data.id');

        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $response = $this->postJson("/api/v1/app/account/payments/{$paymentId}/proof", ['file' => $file], $this->authHeaders($token));

        $response->assertStatus(422);
    }

    public function test_upload_accepts_valid_proof_and_is_authenticated(): void
    {
        [, , $invoice, , $token] = $this->setupTenantWithInvoice();

        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($token))->json('data.id');

        $file = UploadedFile::fake()->image('proof.jpg', 100, 100)->size(200);

        $response = $this->postJson("/api/v1/app/account/payments/{$paymentId}/proof", ['file' => $file], $this->authHeaders($token));
        $response->assertStatus(201);

        // unauthenticated download must be rejected
        $this->getJson("/api/v1/app/account/payments/{$paymentId}/proofs/{$response->json('data.id')}")->assertStatus(401);
    }

    public function test_platform_can_verify_payment_which_updates_invoice_and_activates_subscription(): void
    {
        [$tenant, $contract, $invoice, , $tenantToken] = $this->setupTenantWithInvoice();

        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tenantToken))->json('data.id');

        $this->assertSame('PENDING', $contract->subscription->fresh()->status);

        [, $platformToken] = $this->makePlatformUser(['payment.verify']);
        $response = $this->postJson("/api/v1/platform/payments/{$paymentId}/verify", ['note' => 'ok'], $this->authHeaders($platformToken));
        $response->assertOk()->assertJsonPath('data.status', 'VERIFIED');

        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('ACTIVE', $contract->subscription->fresh()->status);
    }

    public function test_partial_payment_leaves_invoice_partially_paid_and_subscription_pending(): void
    {
        [$tenant, $contract, $invoice, , $tenantToken] = $this->setupTenantWithInvoice();
        $this->assertSame('2000000.00', (string) $invoice->total); // BASIC bundle price from setUp()
        $half = '1000000.00';

        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $half,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tenantToken))->json('data.id');

        [, $platformToken] = $this->makePlatformUser(['payment.verify']);
        $this->postJson("/api/v1/platform/payments/{$paymentId}/verify", [], $this->authHeaders($platformToken))->assertOk();

        $freshInvoice = $invoice->fresh();
        $this->assertSame('PARTIALLY_PAID', $freshInvoice->status);
        $this->assertSame($half, (string) $freshInvoice->paid_amount);
        $this->assertNotSame('0.00', (string) $freshInvoice->outstanding_amount);
        // default activation threshold is full payment — subscription stays PENDING
        $this->assertSame('PENDING', $contract->subscription->fresh()->status);
    }

    public function test_rejected_payment_can_be_resubmitted(): void
    {
        [, , $invoice, , $tenantToken] = $this->setupTenantWithInvoice();

        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tenantToken))->json('data.id');

        [, $platformToken] = $this->makePlatformUser(['payment.reject']);
        $this->postJson("/api/v1/platform/payments/{$paymentId}/reject", ['note' => 'Amount mismatch'], $this->authHeaders($platformToken))
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $resubmit = $this->postJson("/api/v1/app/account/payments/{$paymentId}/resubmit", [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tenantToken));

        $resubmit->assertStatus(201)->assertJsonPath('data.status', 'SUBMITTED');
        $this->assertNotSame($paymentId, $resubmit->json('data.id'));
    }
}
