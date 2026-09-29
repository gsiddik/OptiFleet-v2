<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Component Group soft delete moves from `component_group.update` to its own
 * granular `component_group.delete` permission. To stay backward compatible,
 * every tenant role that could delete before (i.e. holds
 * component_group.update) is granted the new permission here. The permission
 * row itself is created (idempotently) because migrations run before
 * PermissionSeeder on a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleteId = DB::table('permissions')->where('name', 'component_group.delete')->where('scope', 'tenant')->value('id');
        if ($deleteId === null) {
            $deleteId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $deleteId,
                'name' => 'component_group.delete',
                'group' => 'component_group',
                'scope' => 'tenant',
                'description' => 'Delete component group',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $updateId = DB::table('permissions')->where('name', 'component_group.update')->where('scope', 'tenant')->value('id');
        if ($updateId === null) {
            return;
        }

        $roleIds = DB::table('role_permissions')->where('permission_id', $updateId)->pluck('role_id');
        $alreadyGranted = DB::table('role_permissions')->where('permission_id', $deleteId)->pluck('role_id')->all();

        $rows = $roleIds->reject(fn ($roleId) => in_array($roleId, $alreadyGranted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $deleteId, 'created_at' => now(), 'updated_at' => now()])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        // Permission rows are owned by PermissionSeeder; nothing to undo safely.
    }
};
