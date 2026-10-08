<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Permissions of the telematics odometer calibration screen (telematics_link.*). Existing tenants: roles that can
 * view vehicles get view, roles that can update vehicles get manage; both can be re-delegated in the Role Editor.
 * Idempotent; no vehicle data is touched.
 */
return new class extends Migration
{
    private const GRANTS = [
        'telematics_link.view' => ['vehicle.view', 'Telematics links — view readings and calibration'],
        'telematics_link.manage' => ['vehicle.update', 'Telematics links — calibrate GPS distance against the vehicle odometer'],
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
