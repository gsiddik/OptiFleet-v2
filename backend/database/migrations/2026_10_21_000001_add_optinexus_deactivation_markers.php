<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which accounts OptiNexus deactivated (Back-Channel Logout with the
 * access-revoked event), so that a later successful OptiNexus sign-in can undo
 * exactly that and never reactivate an account an OptiFleet administrator
 * switched off. Nullable and additive: nothing changes for unlinked tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('optinexus_deactivated_at')->nullable();
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestamp('optinexus_deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('optinexus_deactivated_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('optinexus_deactivated_at');
        });
    }
};
