<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable canonical identifier for system-defined roles (product owner decision): `roles.code`, e.g.
 * PLATFORM_SUPERADMIN. The display name is never the identity of a system role.
 *
 * - Nullable: tenant-created roles keep code = null.
 * - Unique when set, within its namespace (the platform, or one tenant), so a per-tenant system role such
 *   as TENANT_ADMIN may exist once in every tenant while PLATFORM_SUPERADMIN exists once overall.
 * - Additive and idempotent. Names, permission assignments and user-role assignments are not touched.
 * - Backfill is deterministic only: the single platform-scoped system role named "Platform Superadmin".
 *   If that match is not exactly one row, nothing is guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'code')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('code', 64)->nullable()->after('name');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS roles_code_unique ON roles (COALESCE(tenant_id::text, ''), code) WHERE code IS NOT NULL");
        }

        $alreadyCoded = DB::table('roles')->whereNull('tenant_id')->where('code', 'PLATFORM_SUPERADMIN')->exists();
        $candidates = DB::table('roles')
            ->whereNull('tenant_id')->where('scope', 'platform')->where('is_system', true)
            ->where('name', 'Platform Superadmin')->whereNull('code')
            ->pluck('id');
        if (! $alreadyCoded && $candidates->count() === 1) {
            DB::table('roles')->where('id', $candidates->first())->update(['code' => 'PLATFORM_SUPERADMIN']);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS roles_code_unique');
        }
        if (Schema::hasColumn('roles', 'code')) {
            Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('code'));
        }
    }
};
