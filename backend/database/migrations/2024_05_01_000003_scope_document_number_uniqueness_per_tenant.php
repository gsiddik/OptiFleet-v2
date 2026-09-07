<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Section 4/6: Work Order and Maintenance Request numbers were the
 * only two document numbers still drawn from a single sequence shared
 * across ALL tenants (a Phase 3 artifact — every other document type is
 * already tenant-scoped). Now that numbering is genuinely configurable
 * per tenant/branch/workshop, two tenants issuing their first document of
 * the year would otherwise both produce the identical string and collide
 * against the old GLOBAL unique constraint. Loosening these to per-tenant
 * uniqueness (matching every other document table already in this
 * codebase) never invalidates any existing row — a globally-unique value
 * trivially satisfies a less restrictive per-tenant constraint too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique(['wo_number']);
            $table->unique(['tenant_id', 'wo_number']);
        });

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropUnique(['request_number']);
            $table->unique(['tenant_id', 'request_number']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'request_number']);
            $table->unique('request_number');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'wo_number']);
            $table->unique('wo_number');
        });
    }
};
