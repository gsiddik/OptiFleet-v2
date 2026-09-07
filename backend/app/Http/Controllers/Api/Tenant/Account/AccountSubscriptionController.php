<?php

namespace App\Http\Controllers\Api\Tenant\Account;

use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Subscription\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;

class AccountSubscriptionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly CapacityService $capacity,
    ) {}

    public function show()
    {
        $tenantId = $this->context->tenantId();

        $subscription = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['CANCELLED'])
            ->latest('created_at')
            ->with('contract')
            ->first();

        return $this->ok($subscription);
    }

    public function activeModules()
    {
        $tenantId = $this->context->tenantId();

        return $this->ok($this->entitlements->activeModuleCodes($tenantId)->values());
    }

    public function usageAndLimits()
    {
        $tenantId = $this->context->tenantId();
        $resourceTypes = ['vehicle', 'user', 'branch', 'workshop', 'warehouse'];

        $limits = collect($resourceTypes)->map(fn ($type) => [
            'resource_type' => $type,
            'max_count' => $this->capacity->limitFor($tenantId, $type),
            'current_count' => $this->capacity->currentCount($tenantId, $type),
        ]);

        return $this->ok($limits);
    }
}
