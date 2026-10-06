<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * i18n rollout: existing tenants default to English (the language they have always used), and new tenants
 * get `en` unless set. The column stays nullable — null still means "no tenant default" and the locale
 * falls through to Accept-Language / English. Non-destructive and idempotent: only nulls are filled, a
 * tenant that already chose a locale keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'default_locale')) {
            return;
        }
        DB::table('tenants')->whereNull('default_locale')->update(['default_locale' => 'en']);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tenants ALTER COLUMN default_locale SET DEFAULT 'en'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasColumn('tenants', 'default_locale')) {
            DB::statement('ALTER TABLE tenants ALTER COLUMN default_locale DROP DEFAULT');
        }
    }
};
