<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Component Group Master improvement: a 3-letter, uppercase, alphabetic
 * Abbreviation — a stable business identifier intended for Product SKUs.
 *
 * Nullable at the database level on purpose: tenant-created groups that
 * predate this column have no abbreviation, and one must never be invented
 * for them without the tenant's visibility. The API makes it mandatory for
 * every new group, and the UI surfaces "missing" for legacy rows.
 *
 * Uniqueness deliberately INCLUDES soft-deleted rows (no WHERE deleted_at
 * clause): an abbreviation that may already appear inside historical SKUs is
 * never reused by a different group. Platform rows (tenant_id NULL) are
 * unique among themselves; tenant rows are unique within their tenant. The
 * platform-vs-tenant overlap is enforced by ComponentGroupService under an
 * advisory lock, because a single index cannot express it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_groups', function (Blueprint $table) {
            $table->string('abbreviation', 3)->nullable()->after('name');
        });

        DB::statement("ALTER TABLE component_groups ADD CONSTRAINT component_groups_abbreviation_format CHECK (abbreviation IS NULL OR abbreviation ~ '^[A-Z]{3}$')");
        DB::statement('CREATE UNIQUE INDEX component_groups_platform_abbreviation_unique ON component_groups (abbreviation) WHERE tenant_id IS NULL AND abbreviation IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX component_groups_tenant_abbreviation_unique ON component_groups (tenant_id, abbreviation) WHERE tenant_id IS NOT NULL AND abbreviation IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS component_groups_tenant_abbreviation_unique');
        DB::statement('DROP INDEX IF EXISTS component_groups_platform_abbreviation_unique');
        DB::statement('ALTER TABLE component_groups DROP CONSTRAINT IF EXISTS component_groups_abbreviation_format');

        Schema::table('component_groups', function (Blueprint $table) {
            $table->dropColumn('abbreviation');
        });
    }
};
