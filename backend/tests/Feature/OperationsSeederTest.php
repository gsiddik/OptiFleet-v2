<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsSeeder;
use Tests\TestCase;

/**
 * Regression coverage for the OperationsSeeder compatibility fix: it must
 * run to completion under the current WorkOrderClosureGuardService rules
 * (Finding must be RESOLVED before COMPLETED/CLOSED), producing a real
 * Closed Work Order with no unresolved Finding attached — not a status
 * inserted directly, and not a guard that was weakened to allow it.
 */
class OperationsSeederTest extends TestCase
{
    public function test_operations_seeder_runs_without_closure_guard_failure(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        // Must not throw WorkOrderException from WorkOrderClosureGuardService.
        $this->seed(OperationsSeeder::class);

        $tenant = Tenant::query()->where('code', 'ALPHA')->firstOrFail();
        $workOrder = WorkOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('complaint', 'Brake pedal soft, needs inspection.')
            ->firstOrFail();

        // VehicleReleaseService::release() closes the loop by transitioning
        // COMPLETED -> CLOSED, so the fully-executed scenario ends up Closed.
        $this->assertSame('CLOSED', $workOrder->status);

        $openFindings = $workOrder->findings()->where('status', 'OPEN')->count();
        $this->assertSame(0, $openFindings, 'A Completed/Closed Work Order must have no unresolved Finding.');

        $resolvedFinding = $workOrder->findings()->where('status', 'RESOLVED')->firstOrFail();
        $this->assertNotNull($resolvedFinding->resolved_at);
        $this->assertNotNull($resolvedFinding->resolved_by);
    }
}
