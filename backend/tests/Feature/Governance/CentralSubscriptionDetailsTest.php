<?php

namespace Tests\Feature\Governance;

use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

class CentralSubscriptionDetailsTest extends TestCase
{
    use InteractsWithCentralGovernance;

    public function test_central_details_follow_nexus_dates_status_and_changes_without_local_subscription(): void
    {
        $this->bootCentralGovernance();
        $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['account.subscription.view']);
        $headers = $this->authHeaders($token);
        $row = ['id' => 'subscription-a', 'tenant_id' => $tenant->optinexus_tenant_id, 'status' => 'ACTIVE',
            'start_at' => '2026-10-22T00:00:00Z', 'current_period_end' => '2026-11-22T00:00:00Z', 'grace_end_at' => '2026-11-29T00:00:00Z'];
        $this->nexus->tenants[$tenant->optinexus_tenant_id]['application_subscriptions'] = [$row];
        $this->getJson('/api/v1/app/account/subscription', $headers)->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.start_date', $row['start_at'])
            ->assertJsonPath('data.end_date', $row['current_period_end'])->assertJsonPath('data.next_billing_date', $row['current_period_end']);
        $row['status'] = 'EXPIRED';
        $row['current_period_end'] = '2026-12-22T00:00:00Z';
        $this->nexus->tenants[$tenant->optinexus_tenant_id]['application_subscriptions'] = [$row];
        $this->getJson('/api/v1/app/account/subscription', $headers)->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')->assertJsonPath('data.end_date', $row['current_period_end']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_foreign_tenant_details_fail_closed(): void
    {
        $this->bootCentralGovernance();
        $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['account.subscription.view']);
        $this->nexus->tenants[$tenant->optinexus_tenant_id]['application_subscriptions'] = [
            ['id' => 'foreign', 'tenant_id' => 'other-customer', 'status' => 'ACTIVE'],
        ];
        $this->getJson('/api/v1/app/account/subscription', $this->authHeaders($token))->assertStatus(503);
    }
}
