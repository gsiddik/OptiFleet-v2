<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

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
}
