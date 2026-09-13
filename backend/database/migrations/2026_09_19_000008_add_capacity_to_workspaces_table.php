<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-12 (final reconciliation, partial): "No Bay Type master, no
 * capacity_unit, no combined Bay+WO+Maintainer allocation." Workspace
 * already models the Bay concept (Report 1's own words: "a 'Workspace'
 * feature that partially, narrowly substitutes for the flowcharted 'Bay'
 * concept") via a fixed workspace_type enum — this adds the missing
 * capacity/capacity_unit fields, the one clause safely definable without
 * inventing anything. Converting workspace_type into a tenant-editable
 * "Bay Type" master-data table and building combined Bay+WO+Maintainer
 * allocation both remain DEFERRED_DECISION — see IMPROVEMENT_CONTEXT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable()->after('workspace_type');
            $table->string('capacity_unit')->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'capacity_unit']);
        });
    }
};
