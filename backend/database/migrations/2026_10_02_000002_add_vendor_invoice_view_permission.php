<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vendor Invoice References get their own permission instead of borrowing goods_receipt.view:
 * vendor_invoice.view (list, invoice document view/download). Every role that could see the
 * page before (goods_receipt.view) keeps access — no tenant loses visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'vendor_invoice.view')->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => 'vendor_invoice.view', 'group' => 'vendor_invoice', 'scope' => 'tenant',
                'description' => 'View vendor invoice references and their documents',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $source = DB::table('permissions')->where('name', 'goods_receipt.view')->where('scope', 'tenant')->value('id');
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
        $id = DB::table('permissions')->where('name', 'vendor_invoice.view')->value('id');
        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
