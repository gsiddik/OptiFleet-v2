<?php

namespace Tests\Feature\Dashboard;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dashboard security: permission + module + data scope are enforced on the server for the catalog,
 * every widget, its filters and its drill-down; monetary data is never sent without
 * dashboard.finance.view; tenants are isolated; the cache never crosses access boundaries.
 */
class DashboardSecurityTest extends TestCase
{
    use DashboardTestHelpers;

    public function test_catalog_lists_only_widgets_allowed_by_permission_and_module(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'INVENTORY']);
        [, $none] = $this->makeTenantUser($tenant, []);
        [, $viewer] = $this->makeTenantUser($tenant, ['vehicle.view', 'inventory.view', 'work_order.view']);
        [, $finance] = $this->makeTenantUser($tenant, ['inventory.view', 'dashboard.finance.view']);

        $empty = $this->getJson('/api/v1/app/dashboard/catalog', $this->authHeaders($none))->assertOk()->json('data');
        $this->assertSame([], $empty['presets']);
        $this->assertSame([], $empty['widgets']);

        $catalog = $this->getJson('/api/v1/app/dashboard/catalog', $this->authHeaders($viewer))->assertOk()->json('data');
        $ids = collect($catalog['widgets'])->pluck('id')->all();
        $this->assertContains('FL-01', $ids);
        $this->assertContains('WH-01', $ids);
        $this->assertNotContains('FN-05', $ids, 'Inventory value needs the finance permission.');
        $this->assertNotContains('WS-01', $ids, 'WORK_ORDER module is not active for this tenant.');
        $this->assertNotContains('finance', collect($catalog['presets'])->pluck('id')->all(), 'A tab without allowed widgets is hidden.');

        $financeIds = collect($this->getJson('/api/v1/app/dashboard/catalog', $this->authHeaders($finance))->json('data.widgets'))->pluck('id')->all();
        $this->assertContains('FN-05', $financeIds);
    }

    public function test_widget_endpoint_refuses_missing_permission_inactive_module_and_unknown_widget(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'INVENTORY']);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'inventory.view', 'work_order.view']);

        $this->widget($token, 'FN-05')->assertStatus(403)->assertJsonPath('code', 'dashboard.errors.widgetForbidden');
        $this->widget($token, 'WS-01')->assertStatus(403)->assertJsonPath('code', 'dashboard.errors.moduleInactive');
        $this->widget($token, 'ZZ-99')->assertStatus(404)->assertJsonPath('code', 'dashboard.errors.unknownWidget');
        $this->details($token, 'FN-05')->assertStatus(403);
        $this->widget($token, 'FL-01')->assertOk()->assertJsonPath('data.id', 'FL-01');
    }

    public function test_tenant_isolation_and_branch_scope_on_widget_filter_and_drilldown(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE']);
        $this->grantModules($other, ['VEHICLE']);
        $category = $this->makeVehicleCategory();
        $jakarta = $this->makeBranch($tenant, ['code' => 'JKT', 'name' => 'Jakarta']);
        $bandung = $this->makeBranch($tenant, ['code' => 'BDG', 'name' => 'Bandung']);
        $foreign = $this->makeBranch($other, ['code' => 'FOR', 'name' => 'Foreign']);
        $this->makeVehicle($tenant, $jakarta, $category);
        $this->makeVehicle($tenant, $jakarta, $category, ['status' => 'BREAKDOWN']);
        $this->makeVehicle($tenant, $bandung, $category);
        $this->makeVehicle($tenant, $bandung, $category, ['status' => 'DISPOSED']);
        $this->makeVehicle($other, $foreign, $category);

        [, $admin] = $this->makeTenantUser($tenant, ['vehicle.view']);
        [, $branchUser] = $this->makeTenantUser($tenant, ['vehicle.view'], ['BRANCH' => $bandung->id]);

        $this->widget($admin, 'FL-01')->assertOk()
            ->assertJsonPath('data.data.total', 3)
            ->assertJsonPath('data.data.by_status.BREAKDOWN', 1);
        $this->widget($branchUser, 'FL-01')->assertOk()->assertJsonPath('data.data.total', 1);

        // Filters can only narrow: another branch of the tenant, or another tenant's branch, is refused.
        $this->widget($branchUser, 'FL-01', ['branch_id' => $jakarta->id])->assertStatus(403)->assertJsonPath('code', 'dashboard.errors.filterOutOfScope');
        $this->widget($admin, 'FL-01', ['branch_id' => $foreign->id])->assertStatus(403);
        $this->widget($admin, 'FL-01', ['branch_id' => $jakarta->id])->assertOk()->assertJsonPath('data.data.total', 2);

        // Drill-down uses the same scope and filter validation.
        $this->details($branchUser, 'FL-01', ['branch_id' => $jakarta->id])->assertStatus(403);
        $this->assertCount(1, $this->details($branchUser, 'FL-01')->assertOk()->json('data.data'));
        $this->assertCount(0, $this->details($branchUser, 'FL-01', ['status' => 'BREAKDOWN'])->assertOk()->json('data.data'));
    }

    public function test_cache_never_serves_one_users_scope_to_another(): void
    {
        Cache::flush();
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE']);
        $category = $this->makeVehicleCategory();
        $a = $this->makeBranch($tenant, ['code' => 'A']);
        $b = $this->makeBranch($tenant, ['code' => 'B']);
        $this->makeVehicle($tenant, $a, $category);
        $this->makeVehicle($tenant, $b, $category);
        $this->makeVehicle($tenant, $b, $category);

        [, $userA] = $this->makeTenantUser($tenant, ['vehicle.view'], ['BRANCH' => $a->id]);
        [, $userB] = $this->makeTenantUser($tenant, ['vehicle.view'], ['BRANCH' => $b->id]);
        [, $admin] = $this->makeTenantUser($tenant, ['vehicle.view']);

        $this->widget($userA, 'FL-01')->assertJsonPath('data.data.total', 1);
        $this->widget($userB, 'FL-01')->assertJsonPath('data.data.total', 2);
        $this->widget($admin, 'FL-01')->assertJsonPath('data.data.total', 3);
        $this->widget($userA, 'FL-01')->assertJsonPath('data.data.total', 1);
    }

    public function test_warehouse_records_without_branch_are_not_a_scope_gap(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['INVENTORY', 'PROCUREMENT']);
        $branch = $this->makeBranch($tenant);
        $central = $this->makeWarehouse($tenant, null, null, ['name' => 'Central (no branch)']);
        $branchWarehouse = $this->makeWarehouse($tenant, $branch);
        foreach ([[$central->id, 'PR-C'], [$central->id, 'PR-C2'], [$branchWarehouse->id, 'PR-B']] as [$warehouseId, $number]) {
            PurchaseRequest::query()->create(['tenant_id' => $tenant->id, 'pr_number' => $number, 'warehouse_id' => $warehouseId, 'status' => 'SUBMITTED', 'source_type' => 'MANUAL']);
        }

        [, $admin] = $this->makeTenantUser($tenant, ['purchase_request.view']);
        [, $branchUser] = $this->makeTenantUser($tenant, ['purchase_request.view'], ['BRANCH' => $branch->id]);
        [, $warehouseUser] = $this->makeTenantUser($tenant, ['purchase_request.view'], ['WAREHOUSE' => $central->id]);

        $this->widget($admin, 'PR-01')->assertJsonPath('data.data.purchase_requests.SUBMITTED', 3);
        $this->widget($admin, 'PR-01')->assertJsonPath('data.data.purchase_orders', null);
        $this->widget($branchUser, 'PR-01')->assertJsonPath('data.data.purchase_requests.SUBMITTED', 1);
        $this->widget($warehouseUser, 'PR-01')->assertJsonPath('data.data.purchase_requests.SUBMITTED', 2);
        // Branch filter: only warehouses of that branch — the branch-less central warehouse is not included.
        $this->widget($admin, 'PR-01', ['branch_id' => $branch->id])->assertJsonPath('data.data.purchase_requests.SUBMITTED', 1);
    }

    public function test_work_order_widgets_follow_workshop_scope_and_branch_filter_only_narrows(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'WORKSHOP']);
        $category = $this->makeVehicleCategory();
        $jkt = $this->makeBranch($tenant, ['code' => 'JKT']);
        $bdg = $this->makeBranch($tenant, ['code' => 'BDG']);
        $wsJkt = $this->makeWorkshop($tenant, $jkt);
        $wsBdg = $this->makeWorkshop($tenant, $bdg);
        $vehicleBdg = $this->makeVehicle($tenant, $bdg, $category);
        $vehicleJkt = $this->makeVehicle($tenant, $jkt, $category);
        // A Bandung vehicle serviced in the Jakarta workshop, and one serviced at home.
        $this->makeWorkOrder($tenant, $bdg, $wsJkt, $vehicleBdg, ['status' => 'IN_PROGRESS']);
        $this->makeWorkOrder($tenant, $bdg, $wsBdg, $vehicleBdg, ['status' => 'WAITING_PART']);
        $this->makeWorkOrder($tenant, $jkt, $wsJkt, $vehicleJkt, ['status' => 'QC_PENDING']);

        [, $bdgUser] = $this->makeTenantUser($tenant, ['work_order.view'], ['BRANCH' => $bdg->id]);
        [, $jktWorkshopUser] = $this->makeTenantUser($tenant, ['work_order.view'], ['WORKSHOP' => $wsJkt->id]);
        [, $admin] = $this->makeTenantUser($tenant, ['work_order.view']);

        // Workshop scope (the Work Order list rule): Bandung branch users see Bandung-workshop WOs only.
        $this->widget($bdgUser, 'WS-01')->assertJsonPath('data.data.total', 1)->assertJsonPath('data.data.by_status.WAITING_PART', 1);
        $this->widget($jktWorkshopUser, 'WS-01')->assertJsonPath('data.data.total', 2);
        // The branch filter narrows by business attribution (work_orders.branch_id) inside the accessible WOs:
        // tenant-wide, both Bandung-attributed WOs (in two workshops); for the Bandung user still only one.
        $this->widget($admin, 'WS-01', ['branch_id' => $bdg->id])->assertJsonPath('data.data.total', 2);
        $this->widget($bdgUser, 'WS-01', ['branch_id' => $bdg->id])->assertJsonPath('data.data.total', 1);
        $this->widget($admin, 'WS-01', ['workshop_id' => $wsJkt->id, 'branch_id' => $bdg->id])->assertJsonPath('data.data.total', 1);
        // A workshop-scoped user cannot pick a workshop outside the scope.
        $this->widget($jktWorkshopUser, 'WS-01', ['workshop_id' => $wsBdg->id])->assertStatus(403);
    }

    public function test_legacy_dashboard_no_longer_leaks_money_warranty_or_unscoped_counts(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['INVENTORY', 'WARRANTY', 'ORGANIZATION']);
        $a = $this->makeBranch($tenant, ['code' => 'A']);
        $b = $this->makeBranch($tenant, ['code' => 'B']);
        $warehouse = $this->makeWarehouse($tenant, $a);
        $this->makeWarehouse($tenant, $b);
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 5, 10, 'OPENING', null, null, null);

        [, $viewer] = $this->makeTenantUser($tenant, ['inventory.view'], ['BRANCH' => $a->id]);
        $data = $this->getJson('/api/v1/app/dashboard', $this->authHeaders($viewer))->assertOk()->json('data');
        $this->assertArrayNotHasKey('inventory_total_value', $data);
        $this->assertArrayNotHasKey('warranty_claims_active', $data);
        $this->assertArrayNotHasKey('inventory_reserved_stock', $data);
        $this->assertArrayNotHasKey('users_total', $data);
        $this->assertSame(1, $data['branches_total']);
        $this->assertSame(1, $data['warehouses_total']);

        [, $finance] = $this->makeTenantUser($tenant, ['inventory.view', 'dashboard.finance.view']);
        $this->assertSame('50.00', $this->getJson('/api/v1/app/dashboard', $this->authHeaders($finance))->json('data.inventory_total_value'));
    }

    public function test_permission_migration_grants_finance_to_roles_holding_cost_analytics_only(): void
    {
        $tenant = $this->makeTenant();
        [$costUser] = $this->makeTenantUser($tenant, ['analytics.cost.view']);
        [$otherUser] = $this->makeTenantUser($tenant, ['inventory.view']);
        $finance = DB::table('permissions')->where('name', 'dashboard.finance.view')->value('id');
        DB::table('role_permissions')->where('permission_id', $finance)->delete();

        $migration = require base_path('database/migrations/2026_10_16_000001_add_dashboard_finance_permission.php');
        $migration->up();
        $migration->up(); // idempotent

        $holders = fn ($user) => RoleAssignment::query()->where('user_id', $user->id)
            ->whereHas('role.permissions', fn ($q) => $q->where('name', 'dashboard.finance.view'))->exists();
        $this->assertTrue($holders($costUser));
        $this->assertFalse($holders($otherUser));
        $this->assertSame(1, DB::table('role_permissions')->where('permission_id', $finance)->count());
    }
}
