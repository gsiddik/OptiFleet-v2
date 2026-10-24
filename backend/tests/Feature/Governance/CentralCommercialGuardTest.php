<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Subscription\Services\DunningService;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Wave 6 / audit G9: OptiNexus bills a CENTRAL tenant, so the Superadmin commercial routes and the billing jobs leave it alone. */
class CentralCommercialGuardTest extends TestCase
{
    private function centralSubscription(int $dueDaysAgo = 3): array
    {
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
        $tenant = $this->makeTenant();
        $contract = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]));
        $invoice = $contract->subscription->invoices()->first();
        $invoice->update(['due_date' => now()->subDays($dueDaysAgo)->toDateString()]);
        $tenant->forceFill(['governance_mode' => 'CENTRAL', 'optinexus_tenant_id' => (string) Str::uuid()])->save();
        app(CentralGovernance::class)->flushMemo();

        return [$tenant, $contract->fresh(), $invoice];
    }

    public function test_dunning_and_expiry_jobs_skip_a_central_tenant(): void
    {
        [, $contract, $invoice] = $this->centralSubscription();

        app(DunningService::class)->evaluateOverdueInvoices();
        app(DunningService::class)->evaluateContractAndSubscriptionExpiry(now()->addYears(5));

        $this->assertSame('OUTSTANDING', $invoice->fresh()->status);
        $this->assertSame('ACTIVE', $contract->subscription->fresh()->status);
        $this->assertSame('ACTIVE', $contract->fresh()->status);
    }

    public function test_billing_job_does_not_generate_for_a_central_tenant(): void
    {
        [, $contract] = $this->centralSubscription();
        $contract->subscription->forceFill(['next_billing_date' => now()->subDay()->toDateString()])->save();
        $before = $contract->subscription->billings()->count();

        $this->artisan('billing:generate')->assertExitCode(0);

        $this->assertSame($before, $contract->subscription->billings()->count());
    }

    public function test_superadmin_commercial_writes_for_a_central_tenant_answer_409(): void
    {
        [$tenant, $contract, $invoice] = $this->centralSubscription();
        [, $token] = $this->makePlatformUser(['contract.create', 'contract.update', 'contract.terminate', 'billing.generate', 'invoice.generate', 'invoice.issue', 'invoice.void', 'subscription.suspend']);
        $h = $this->authHeaders($token);

        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/contracts", [], $h)->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->postJson("/api/v1/platform/contracts/{$contract->id}/terminate", ['reason' => 'x'], $h)->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->postJson("/api/v1/platform/subscriptions/{$contract->subscription->id}/generate-billing", [], $h)->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->postJson("/api/v1/platform/subscriptions/{$contract->subscription->id}/suspend", [], $h)->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->postJson("/api/v1/platform/invoices/{$invoice->id}/void", ['reason' => 'x'], $h)->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->getJson('/api/v1/platform/invoices', $h)->assertStatus(403); // reading needs its own permission; the guard only blocks writes
    }

    public function test_a_local_tenant_keeps_its_commercial_writes(): void
    {
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/contracts", [], $this->authHeaders($token))->assertStatus(422);
    }
}
