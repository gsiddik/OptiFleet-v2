<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mechanic Performance baselines get their own permission, mechanic_baseline.manage (owner decision:
 * Tenant Admin). Existing tenants: granted to the roles that administer access
 * (role.assign_permission, held by Tenant Admin), so it can then be delegated in the Role Editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'mechanic_baseline.manage')->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => 'mechanic_baseline.manage', 'group' => 'dashboard', 'scope' => 'tenant',
                'description' => 'Manage mechanic performance baselines',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $source = DB::table('permissions')->where('name', 'role.assign_permission')->where('scope', 'tenant')->value('id');
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
        $id = DB::table('permissions')->where('name', 'mechanic_baseline.manage')->value('id');
        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
