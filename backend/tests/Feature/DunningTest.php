<?php

namespace Tests\Feature;

use App\Domain\Subscription\Services\DunningService;
use Tests\TestCase;

class DunningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    private function activeContractWithBackdatedInvoice(int $dueDaysAgo, int $gracePeriodDays = 7): array
    {
        $tenant = $this->makeTenant();
        $contract = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', [
            'activation_requires_payment' => false,
            'grace_period_days' => $gracePeriodDays,
        ]));

        $invoice = $contract->subscription->invoices()->first();
        $invoice->update(['due_date' => now()->subDays($dueDaysAgo)->toDateString()]);

        return [$tenant, $contract];
    }

    public function test_overdue_invoice_transitions_subscription_to_past_due(): void
    {
        [, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 3);

        app(DunningService::class)->evaluateOverdueInvoices();

        $this->assertSame('OVERDUE', $contract->subscription->invoices()->first()->fresh()->status);
        $this->assertSame('PAST_DUE', $contract->subscription->fresh()->status);
    }

    public function test_past_due_within_grace_window_enters_grace_period(): void
    {
        [, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 3, gracePeriodDays: 7);

        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $dunning->evaluateGraceAndSuspension();

        $this->assertSame('GRACE_PERIOD', $contract->subscription->fresh()->status);
        $this->assertNotNull($contract->subscription->fresh()->grace_period_end);
    }

    public function test_subscription_auto_suspends_after_grace_period_expires(): void
    {
        [, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 20, gracePeriodDays: 7);

        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $dunning->evaluateGraceAndSuspension();

        $this->assertSame('SUSPENDED', $contract->subscription->fresh()->status);
        $this->assertNotNull($contract->subscription->fresh()->suspended_at);
    }

    public function test_suspended_tenant_operational_access_is_denied(): void
    {
        [$tenant, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 20, gracePeriodDays: 7);
        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $dunning->evaluateGraceAndSuspension();

        [, $token] = $this->makeTenantUser($tenant, ['branch.view']);
        $this->grantModule($tenant, 'ORGANIZATION');

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $response->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_SUSPENDED');
    }

    public function test_suspended_tenant_keeps_billing_only_account_access(): void
    {
        [$tenant, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 20, gracePeriodDays: 7);
        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $dunning->evaluateGraceAndSuspension();

        [, $token] = $this->makeTenantUser($tenant, ['account.subscription.view', 'account.invoice.view']);

        $this->getJson('/api/v1/app/account/subscription', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/app/account/invoices', $this->authHeaders($token))->assertOk();
    }

    public function test_automatic_reactivation_after_full_payment_verified(): void
    {
        [$tenant, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 20, gracePeriodDays: 7);
        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $dunning->evaluateGraceAndSuspension();
        $this->assertSame('SUSPENDED', $contract->subscription->fresh()->status);

        $invoice = $contract->subscription->invoices()->first();
        [, $tenantToken] = $this->makeTenantUser($tenant, ['account.payment.submit']);
        $paymentId = $this->postJson('/api/v1/app/account/payments', [
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'payment_method' => 'BANK_TRANSFER',
        ], $this->authHeaders($tenantToken))->json('data.id');

        [, $platformToken] = $this->makePlatformUser(['payment.verify']);
        $this->postJson("/api/v1/platform/payments/{$paymentId}/verify", [], $this->authHeaders($platformToken))->assertOk();

        $freshSub = $contract->subscription->fresh();
        $this->assertSame('ACTIVE', $freshSub->status);
        $this->assertNull($freshSub->suspended_at); // cleared on reactivation

        // operational access restored
        [, $opToken] = $this->makeTenantUser($tenant, ['branch.view']);
        $this->grantModule($tenant, 'ORGANIZATION');
        $this->getJson('/api/v1/app/branches', $this->authHeaders($opToken))->assertOk();
    }

    public function test_dunning_is_idempotent(): void
    {
        [, $contract] = $this->activeContractWithBackdatedInvoice(dueDaysAgo: 3);

        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $result = $dunning->evaluateOverdueInvoices(); // second run should be a no-op

        $this->assertSame(0, $result['invoices_marked_overdue']);
        $this->assertSame(0, $result['subscriptions_marked_past_due']);
    }
}
