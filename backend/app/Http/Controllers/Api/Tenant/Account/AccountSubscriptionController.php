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
        private readonly \App\Domain\Governance\CentralGovernance $central,
        private readonly \App\Domain\Governance\NexusGovernanceClient $nexus,
    ) {}

    public function show()
    {
        $tenantId = $this->context->tenantId();

        if ($this->central->governs($tenantId)) {
            $link = $this->central->link($tenantId);
            if (! $link['nexus_tenant_id']) {
                throw new \App\Domain\Governance\Exceptions\GovernanceContractException('The tenant is not linked to OptiNexus.');
            }
            $context = $this->nexus->commercialContext($link['nexus_tenant_id']);
            $rows = $context['application_subscriptions'];
            if ($rows === null) {
                throw new \App\Domain\Governance\Exceptions\GovernanceContractException('Update OptiNexus to provide application subscriptions.');
            }
            $row = $rows[0] ?? null;

            return $this->ok($row ? [
                'id' => $row['id'], 'tenant_id' => $tenantId, 'status' => $row['status'],
                'start_date' => $row['start_at'] ?? null,
                'end_date' => $row['current_period_end'] ?? null,
                'next_billing_date' => $row['current_period_end'] ?? null,
                'grace_period_end' => $row['grace_end_at'] ?? null,
                'source' => 'OPTINEXUS', 'nexus_tenant_id' => $link['nexus_tenant_id'],
                'details' => $row,
            ] : null);
        }

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

        $centralLimits = null;
        if ($this->central->governs($tenantId)) {
            $link = $this->central->link($tenantId);
            if (! $link['nexus_tenant_id']) {
                throw new \App\Domain\Governance\Exceptions\GovernanceContractException('The tenant is not linked to OptiNexus.');
            }
            $commercial = $this->nexus->commercialContext($link['nexus_tenant_id']);
            $centralLimits = $commercial['resource_limits'] ?? null;
            if ($centralLimits === null) {
                throw new \App\Domain\Governance\Exceptions\GovernanceContractException('Update OptiNexus to provide application resource limits.');
            }
        }
        // Exact resource keys only: e.g. vehicle maps to vehicle, not vehicles or vehicle_limit.
        // Missing keys are Unlimited for CENTRAL tenants. LOCAL capacity behavior is unchanged.
        $limits = collect($resourceTypes)->map(fn ($type) => [
            'resource_type' => $type,
            'max_count' => $centralLimits !== null ? ($centralLimits[$type] ?? null) : $this->capacity->limitFor($tenantId, $type),
            'current_count' => $this->capacity->currentCount($tenantId, $type),
        ]);

        return $this->ok($limits);
    }
}
