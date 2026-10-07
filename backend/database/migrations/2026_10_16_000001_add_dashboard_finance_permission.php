<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant Dashboard: monetary widgets get their own permission, dashboard.finance.view (owner
 * decision). Existing tenants: every role that already holds analytics.cost.view (seeded to Tenant
 * Admin, Branch Admin and Auditor) receives it, so the people who see cost analytics keep seeing
 * dashboard money; everybody else no longer receives monetary values on the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'dashboard.finance.view')->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => 'dashboard.finance.view', 'group' => 'dashboard', 'scope' => 'tenant',
                'description' => 'View financial dashboard widgets',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $source = DB::table('permissions')->where('name', 'analytics.cost.view')->where('scope', 'tenant')->value('id');
        if ($source === null) {
            return;
        }
        $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
        $rows = DB::table('role_permissions')->where('permission_id', $source)->distinct()->pluck('role_id')
            ->reject(fn ($roleId) => in_array($roleId, $granted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'dashboard.finance.view')->value('id');
        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
