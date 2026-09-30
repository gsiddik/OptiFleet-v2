<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Work Order Reject is now gated by its own (long-seeded, previously unused)
 * `work_order.reject` permission instead of `work_order.approve`. Every role
 * that could reject before (held work_order.approve) is granted it, so no
 * existing user loses the action. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rejectId = DB::table('permissions')->where('name', 'work_order.reject')->where('scope', 'tenant')->value('id');
        if ($rejectId === null) {
            $rejectId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $rejectId, 'name' => 'work_order.reject', 'group' => 'work_order', 'scope' => 'tenant',
                'description' => 'Reject work order', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $approveId = DB::table('permissions')->where('name', 'work_order.approve')->where('scope', 'tenant')->value('id');
        if ($approveId === null) {
            return;
        }

        $granted = DB::table('role_permissions')->where('permission_id', $rejectId)->pluck('role_id')->all();
        $rows = DB::table('role_permissions')->where('permission_id', $approveId)->pluck('role_id')
            ->reject(fn ($roleId) => in_array($roleId, $granted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $rejectId, 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        // Permission rows are owned by PermissionSeeder; grants are kept.
    }
};
