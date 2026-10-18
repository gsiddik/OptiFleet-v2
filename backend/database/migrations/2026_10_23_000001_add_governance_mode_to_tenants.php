<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central governance (audit A02, R05). Additive and backward compatible: every existing tenant is created as LOCAL, which keeps
 * the roles, data scopes and module entitlements it has today. A tenant becomes CENTRAL only through the operator command
 * `optinexus:governance:enable`, after the inventory and linking plan were reviewed. CENTRAL means OptiNexus is the only
 * authority for who may use the application and what they may do; local roles and data scopes can then only restrict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('governance_mode', 10)->default('LOCAL');
            $table->timestamp('governance_enabled_at')->nullable();
            $table->uuid('governance_enabled_by')->nullable();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_governance_mode_check CHECK (governance_mode IN ('LOCAL', 'CENTRAL'))");
        // A CENTRAL tenant must be linked to its OptiNexus tenant: the link is what the authority is asked about.
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_central_requires_link_check CHECK (governance_mode <> 'CENTRAL' OR optinexus_tenant_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_central_requires_link_check');
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_governance_mode_check');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['governance_mode', 'governance_enabled_at', 'governance_enabled_by']));
    }
};
