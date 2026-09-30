<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Invoice\Services\InvoiceService;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $query = Subscription::query()->with(['tenant', 'contract']);

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function show(Subscription $subscription)
    {
        return $this->ok($subscription->load(['tenant', 'contract.items', 'billings', 'invoices']));
    }

    public function suspend(Request $request, Subscription $subscription)
    {
        return $this->ok($this->subscriptions->suspend($subscription, $request->input('reason')));
    }

    public function reactivate(Subscription $subscription)
    {
        return $this->ok($this->subscriptions->reactivate($subscription));
    }

    public function activate(Request $request, Subscription $subscription)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $activated = $this->subscriptions->activatePending($subscription);
        $this->audit->log('Subscription', $subscription->id, 'manually_activated', ['status' => $subscription->status], ['status' => $activated->status, 'reason' => $validated['reason']], $subscription->tenant_id);

        return $this->ok($activated);
    }

    public function raiseAdjustment(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $invoice = $this->invoices->raiseAdjustment($subscription, $validated['amount'], $validated['description']);
        $this->audit->log('Invoice', $invoice->id, 'adjustment_raised', null, ['subscription_id' => $subscription->id, 'amount' => (string) $invoice->total, 'description' => $validated['description']], $subscription->tenant_id);

        return $this->ok($invoice, 201);
    }
}
