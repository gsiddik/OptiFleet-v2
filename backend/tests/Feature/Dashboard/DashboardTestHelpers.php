<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Fixture helpers shared by the dashboard feature tests. */
trait DashboardTestHelpers
{
    protected function grantModules(Tenant $tenant, array $codes): void
    {
        foreach ($codes as $code) {
            $this->grantModule($tenant, $code);
        }
    }

    protected function widget(string $token, string $id, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/app/dashboard/widgets/'.$id.($query ? '?'.http_build_query($query) : ''), $this->authHeaders($token));
    }

    protected function details(string $token, string $id, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/app/dashboard/widgets/'.$id.'/details'.($query ? '?'.http_build_query($query) : ''), $this->authHeaders($token));
    }

    protected function makeWorkOrder(Tenant $tenant, Branch $branch, Workshop $workshop, Vehicle $vehicle, array $overrides = []): WorkOrder
    {
        return WorkOrder::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'wo_number' => 'WO-'.Str::upper(Str::random(8)),
            'branch_id' => $branch->id,
            'workshop_id' => $workshop->id,
            'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE',
            'priority' => 'MEDIUM',
            'status' => 'SUBMITTED',
        ], $overrides));
    }
}
