<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Billing\Models\Billing;
use App\Domain\Billing\Services\BillingGenerationService;
use App\Domain\Invoice\Services\InvoiceService;
use App\Domain\Subscription\Models\Subscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingGenerationService $generation,
        private readonly InvoiceService $invoices,
    ) {}

    public function index(Request $request)
    {
        $query = Billing::query()->with(['tenant', 'contract']);

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($tenantId = $request->string('tenant_id')->value()) {
            $query->where('tenant_id', $tenantId);
        }

        return $this->paginated($query->latest('billing_period_start')->paginate($request->integer('per_page', 15)));
    }

    public function show(Billing $billing)
    {
        return $this->ok($billing->load(['items', 'tenant', 'contract']));
    }

    /**
     * Manual trigger for a specific subscription — the scheduler
     * (billing:generate) is the normal path; this exists for platform
     * operators to generate on demand. Idempotent like the scheduler.
     */
    public function generate(Subscription $subscription)
    {
        $billing = $this->generation->generateForSubscription($subscription);
        $this->invoices->generateFromBilling($billing);

        return $this->ok($billing->fresh('items'), 201);
    }
}
