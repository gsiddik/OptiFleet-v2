<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Section 1/2: the generic configuration framework every other
 * Phase 5 subsystem (numbering/template/workflow/notification) is built
 * on. A ConfigurationSet identifies one configurable "thing" (e.g. the
 * Work Order numbering rule, or the Maintenance Request workflow) for a
 * tenant at a given scope; a ConfigurationVersion is its immutable,
 * versioned DRAFT/PUBLISHED/ARCHIVED payload. tenant_id NULL = a
 * platform-level default (seeded, is_system=true) every tenant falls
 * back to when it has not customized that configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('type'); // NUMBERING | TEMPLATE | WORKFLOW | NOTIFICATION
            $table->string('code'); // stable identifier, e.g. "work_order", "purchase_order"
            $table->string('scope_type')->default('TENANT'); // TENANT | BRANCH | WORKSHOP | WAREHOUSE
            $table->uuid('scope_resource_id')->nullable();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'type', 'code']);
            $table->index(['scope_type', 'scope_resource_id']);
        });

        // Section 2: at most one TENANT-scoped set per (tenant, type, code);
        // at most one platform-default set per (type, code). Partial
        // indexes because Postgres treats NULLs as distinct in a plain
        // UNIQUE constraint, which would otherwise allow duplicates here.
        DB::statement("CREATE UNIQUE INDEX configuration_sets_tenant_scope_unique ON configuration_sets (tenant_id, type, code) WHERE scope_type = 'TENANT' AND tenant_id IS NOT NULL AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX configuration_sets_platform_default_unique ON configuration_sets (type, code) WHERE tenant_id IS NULL AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX configuration_sets_scoped_unique ON configuration_sets (tenant_id, type, code, scope_type, scope_resource_id) WHERE scope_resource_id IS NOT NULL AND deleted_at IS NULL");

        Schema::create('configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('configuration_set_id');
            $table->unsignedInteger('version_number');
            $table->string('status')->default('DRAFT'); // DRAFT | PUBLISHED | ARCHIVED
            $table->jsonb('payload');
            $table->text('change_summary')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('configuration_set_id')->references('id')->on('configuration_sets')->cascadeOnDelete();
            $table->unique(['configuration_set_id', 'version_number']);
            $table->index(['configuration_set_id', 'status']);
        });

        // At most one PUBLISHED version per configuration set at any time.
        DB::statement("CREATE UNIQUE INDEX configuration_versions_published_unique ON configuration_versions (configuration_set_id) WHERE status = 'PUBLISHED'");
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_versions');
        Schema::dropIfExists('configuration_sets');
    }
};
