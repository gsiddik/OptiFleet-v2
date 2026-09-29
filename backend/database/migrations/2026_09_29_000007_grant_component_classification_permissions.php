<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Component Category / Subcategory management gets its own granular tenant
 * permissions. Whoever may already manage Component Groups is granted the
 * equivalent action on the new levels (view->view, create->create,
 * update->update, delete->delete), so the taxonomy is usable immediately
 * after deploy without hand-editing every role. Permission rows are created
 * idempotently because migrations run before PermissionSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['component_category', 'component_subcategory'] as $group) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissionId = $this->permissionId("{$group}.{$action}", $group, $action);
                $sourceId = DB::table('permissions')->where('name', "component_group.{$action}")->where('scope', 'tenant')->value('id');
                if ($sourceId === null) {
                    continue;
                }

                $granted = DB::table('role_permissions')->where('permission_id', $permissionId)->pluck('role_id')->all();
                $rows = DB::table('role_permissions')->where('permission_id', $sourceId)->pluck('role_id')
                    ->reject(fn ($roleId) => in_array($roleId, $granted, true))
                    ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()])
                    ->values()->all();
                if ($rows !== []) {
                    DB::table('role_permissions')->insert($rows);
                }
            }
        }
    }

    public function down(): void
    {
        // Permission rows are owned by PermissionSeeder; nothing to undo safely.
    }

    private function permissionId(string $name, string $group, string $action): string
    {
        $id = DB::table('permissions')->where('name', $name)->where('scope', 'tenant')->value('id');
        if ($id !== null) {
            return $id;
        }

        $id = (string) Str::uuid();
        DB::table('permissions')->insert([
            'id' => $id, 'name' => $name, 'group' => $group, 'scope' => 'tenant',
            'description' => ucfirst($action).' '.str_replace('_', ' ', $group),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
};
