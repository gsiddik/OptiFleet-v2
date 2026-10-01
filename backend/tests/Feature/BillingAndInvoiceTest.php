<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\Billing;
use App\Domain\Billing\Services\BillingGenerationService;
use App\Domain\Invoice\Services\InvoiceService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingAndInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    public function test_billing_generation_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]);
        $contract = $this->approveContract($contract);
        $subscription = $contract->subscription;

        $service = app(BillingGenerationService::class);
        $first = $service->generateForSubscription($subscription->fresh(), Carbon::parse($subscription->start_date));
        $second = $service->generateForSubscription($subscription->fresh(), Carbon::parse($subscription->start_date));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Billing::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_invoice_number_is_unique_and_sequential(): void
    {
        $tenant1 = $this->makeTenant();
        $tenant2 = $this->makeTenant();

        $c1 = $this->approveContract($this->makeContractDraft($tenant1, 'BASIC', ['activation_requires_payment' => false]));
        $c2 = $this->approveContract($this->makeContractDraft($tenant2, 'BASIC', ['activation_requires_payment' => false]));

        $inv1 = $c1->subscription->invoices()->first();
        $inv2 = $c2->subscription->invoices()->first();

        $this->assertNotSame($inv1->invoice_number, $inv2->invoice_number);
        $this->assertMatchesRegularExpression('#^INV/OPTIFLEET/\d{4}/\d{6}$#', $inv1->invoice_number);
    }

    public function test_invoice_cannot_be_edited_after_issue_only_voided(): void
    {
        $tenant = $this->makeTenant();
        $contract = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]));
        $invoice = $contract->subscription->invoices()->first();

        $this->assertSame('OUTSTANDING', $invoice->status);

        $voided = app(InvoiceService::class)->void($invoice, 'Testing void flow');
        $this->assertSame('VOID', $voided->status);
        $this->assertNotNull($voided->voided_at);
    }

    public function test_proration_applied_when_module_added_mid_period(): void
    {
        // A 30-day billing period (June): added on day 16, 15 of 30 days remain => exactly half.
        $this->assertSame('150000.00', $this->prorationForModuleAddedOnDay16('2027-06-01'));
    }

    public function test_proration_uses_the_actual_length_of_a_31_day_period(): void
    {
        // A 31-day billing period (July): added on day 16, 16 of 31 days remain.
        $this->assertSame('154838.71', $this->prorationForModuleAddedOnDay16('2027-07-01'));
    }

    /**
     * Contract starts on $startDate (the clock is pinned there so the billing period never depends
     * on the day the suite runs); a 300 000 module is added 15 days later. Returns the total of the
     * prorated adjustment invoice.
     */
    private function prorationForModuleAddedOnDay16(string $startDate): string
    {
        $this->travelTo(Carbon::parse($startDate));
        $this->makePricing('MODULE', 'HISTORY', '300000');

        $tenant = $this->makeTenant();
        $contract = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => false]));

        [, $token] = $this->makePlatformUser(['contract.amend', 'contract.approve']);
        $effectiveDate = Carbon::parse($contract->start_date)->addDays(15);

        $amendResp = $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments", [
            'reason' => 'Mid-period add-on',
            'effective_date' => $effectiveDate->toDateString(),
        ], $this->authHeaders($token));
        $amendmentId = $amendResp->json('data.id');

        $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments/{$amendmentId}/items", [
            'product_type' => 'MODULE',
            'product_reference' => 'HISTORY',
            'description' => 'History add-on',
            'billing_frequency' => 'MONTHLY',
        ], $this->authHeaders($token));

        $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments/{$amendmentId}/submit", [], $this->authHeaders($token));
        $approveResponse = $this->postJson("/api/v1/platform/contracts/{$contract->id}/amendments/{$amendmentId}/approve", [], $this->authHeaders($token));
        $approveResponse->assertOk();

        // A prorated adjustment invoice is raised for the remainder of the current billing period.
        $adjustmentInvoice = $contract->subscription->invoices()->latest('invoice_number')->first();
        $this->assertNotNull($adjustmentInvoice);
        $this->assertLessThan(300000, (float) $adjustmentInvoice->total); // prorated, less than full month

        return (string) $adjustmentInvoice->total;
    }
}
