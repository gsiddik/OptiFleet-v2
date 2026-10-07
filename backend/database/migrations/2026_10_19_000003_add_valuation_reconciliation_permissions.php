<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Permissions of the valuation review and the installation reconciliation (inventory_valuation.*, inventory_reconcile.*).
 * Existing tenants: the view permission goes to roles that already see financial dashboards (dashboard.finance.view),
 * the verify / manage / approve permissions to the roles that administer access (role.assign_permission) — they can then
 * be delegated in the Role Editor, like mechanic_baseline.manage. Idempotent; no stock data is touched.
 */
return new class extends Migration
{
    private const GRANTS = [
        'inventory_valuation.view' => ['dashboard.finance.view', 'Inventory valuation status — view'],
        'inventory_valuation.verify' => ['role.assign_permission', 'Inventory valuation status — review and verify'],
        'inventory_reconcile.view' => ['role.assign_permission', 'Installation reconciliation — view report and adjustments'],
        'inventory_reconcile.manage' => ['role.assign_permission', 'Installation reconciliation — propose and apply adjustments'],
        'inventory_reconcile.approve' => ['role.assign_permission', 'Installation reconciliation — approve or reject adjustments'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => [$source, $description]) {
            $id = DB::table('permissions')->where('name', $name)->where('scope', 'tenant')->value('id');
            if ($id === null) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $id, 'name' => $name, 'group' => explode('.', $name)[0], 'scope' => 'tenant', 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
            }
            $sourceId = DB::table('permissions')->where('name', $source)->where('scope', 'tenant')->value('id');
            if ($sourceId === null) {
                continue;
            }
            $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
            $rows = DB::table('role_permissions')->where('permission_id', $sourceId)->distinct()->pluck('role_id')
                ->reject(fn ($roleId) => in_array($roleId, $granted, true))
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('role_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
    }
};
