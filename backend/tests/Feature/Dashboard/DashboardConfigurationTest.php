<?php

namespace Tests\Feature\Dashboard;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Dashboard\Models\MechanicPerformanceBaseline;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Mechanic baselines (permission, module, validation, tenant isolation) and nullable thresholds. */
class DashboardConfigurationTest extends TestCase
{
    use DashboardTestHelpers;

    public function test_baselines_require_permission_and_module_and_stay_per_tenant(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.Str::random(4)]);
        $other = $this->makeTenant(['code' => 'CFG-'.Str::random(4)]);
        [, $manager] = $this->makeTenantUser($tenant, ['mechanic_baseline.manage']);
        [, $viewer] = $this->makeTenantUser($tenant, ['work_order.view']);
        $url = '/api/v1/app/dashboard/mechanic-baselines';

        [, $noModule] = $this->makeTenantUser($other, ['mechanic_baseline.manage']);
        $this->getJson($url, $this->authHeaders($noModule))->assertStatus(403); // WORK_ORDER module not active
        $this->grantModules($tenant, ['WORK_ORDER']);
        $this->getJson($url, $this->authHeaders($viewer))->assertStatus(403);

        $rows = $this->getJson($url, $this->authHeaders($manager))->assertOk()->json('data');
        $this->assertSame(MechanicPerformanceBaseline::MAINTENANCE_TYPES, array_column($rows, 'maintenance_type'));
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'baseline_hours'))), 'nothing is set by default');

        $this->putJson($url, ['baselines' => [['maintenance_type' => 'PREVENTIVE', 'baseline_hours' => 0]]], $this->authHeaders($manager))->assertStatus(422);
        $this->putJson($url, ['baselines' => [['maintenance_type' => 'OTHER', 'baseline_hours' => 2]]], $this->authHeaders($manager))->assertStatus(422);
        $this->putJson($url, ['baselines' => [['maintenance_type' => 'PREVENTIVE', 'baseline_hours' => 2.5]]], $this->authHeaders($viewer))->assertStatus(403);

        $saved = $this->putJson($url, ['baselines' => [
            ['maintenance_type' => 'PREVENTIVE', 'baseline_hours' => 2.5],
            ['maintenance_type' => 'CORRECTIVE', 'baseline_hours' => 6],
        ]], $this->authHeaders($manager))->assertOk()->json('data');
        $this->assertSame('2.50', collect($saved)->firstWhere('maintenance_type', 'PREVENTIVE')['baseline_hours']);

        // Clearing = "not set" again; the other tenant never sees or changes these rows.
        $this->putJson($url, ['baselines' => [['maintenance_type' => 'CORRECTIVE', 'baseline_hours' => null]]], $this->authHeaders($manager))->assertOk();
        $this->assertSame(['PREVENTIVE'], MechanicPerformanceBaseline::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('maintenance_type')->all());
        $this->assertSame(0, MechanicPerformanceBaseline::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->count());
    }

    public function test_threshold_can_be_cleared_or_set_to_an_intentional_zero(): void
    {
        $tenant = $this->makeTenant(['code' => 'THR-'.Str::random(4)]);
        $this->grantModules($tenant, ['INVENTORY']);
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);
        $stock = WarehouseStock::query()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 5,
        ]);
        $this->assertNull($stock->fresh()->reorder_point, 'a new stock row has no threshold until a user sets one');

        [, $token] = $this->makeTenantUser($tenant, ['inventory.view', 'inventory.adjust']);
        $url = "/api/v1/app/inventory/{$stock->id}/thresholds";
        $this->putJson($url, ['reorder_point' => 0], $this->authHeaders($token))->assertOk();
        $this->assertSame('0.0000', $stock->fresh()->reorder_point);
        $this->putJson($url, ['reorder_point' => null], $this->authHeaders($token))->assertOk();
        $this->assertNull($stock->fresh()->reorder_point);
        // Minimum stock has no "not set" state (NOT NULL, default 0): clearing it in the same dialog
        // stores 0 instead of failing.
        $this->putJson($url, ['minimum_stock' => null, 'reorder_point' => 3], $this->authHeaders($token))->assertOk();
        $this->assertSame('0.0000', $stock->fresh()->minimum_stock);
    }

    public function test_migrations_grant_baseline_permission_to_access_administrators_and_clear_default_thresholds(): void
    {
        $tenant = $this->makeTenant(['code' => 'MIG-'.Str::random(4)]);
        [$admin] = $this->makeTenantUser($tenant, ['role.assign_permission']);
        [$other] = $this->makeTenantUser($tenant, ['worker.manage']);
        $id = DB::table('permissions')->where('name', 'mechanic_baseline.manage')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();

        $migration = require base_path('database/migrations/2026_10_17_000004_add_mechanic_baseline_permission.php');
        $migration->up();
        $migration->up(); // idempotent
        $holds = fn ($user) => RoleAssignment::query()->where('user_id', $user->id)
            ->whereHas('role.permissions', fn ($q) => $q->where('name', 'mechanic_baseline.manage'))->exists();
        $this->assertTrue($holds($admin));
        $this->assertFalse($holds($other));

        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $zero = DB::table('warehouse_stocks')->insertGetId(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $this->makeProduct($tenant)->id, 'quantity_on_hand' => 1, 'reorder_point' => 0, 'created_at' => now(), 'updated_at' => now()], 'id');
        $set = DB::table('warehouse_stocks')->insertGetId(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $this->makeProduct($tenant)->id, 'quantity_on_hand' => 1, 'reorder_point' => 4, 'created_at' => now(), 'updated_at' => now()], 'id');
        (require base_path('database/migrations/2026_10_17_000003_make_warehouse_stock_reorder_point_nullable.php'))->up();
        $this->assertNull(DB::table('warehouse_stocks')->where('id', $zero)->value('reorder_point'));
        $this->assertSame('4.0000', DB::table('warehouse_stocks')->where('id', $set)->value('reorder_point'));
    }
}
