<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Section 39: a Maintenance Request may originate from an
 * accepted intelligence recommendation. Adds 'INTELLIGENCE' to the
 * existing source_type check constraint (additive — every existing value
 * stays valid) plus nullable linkage columns so the
 * prediction -> recommendation -> maintenance_request chain stays
 * traceable without touching MaintenanceRequestService's existing
 * signature/behavior for every other source_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE maintenance_requests DROP CONSTRAINT IF EXISTS maintenance_requests_source_type_check');
        DB::statement("ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_source_type_check CHECK (source_type IN ('USER','INSPECTION','SCHEDULE','BREAKDOWN','TELEMATICS','MECHANIC','INTELLIGENCE'))");

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->uuid('source_recommendation_id')->nullable()->after('source_breakdown_id');
            $table->string('source_prediction_id')->nullable()->after('source_recommendation_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn(['source_recommendation_id', 'source_prediction_id']);
        });

        DB::statement('ALTER TABLE maintenance_requests DROP CONSTRAINT IF EXISTS maintenance_requests_source_type_check');
        DB::statement("ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_requests_source_type_check CHECK (source_type IN ('USER','INSPECTION','SCHEDULE','BREAKDOWN','TELEMATICS','MECHANIC'))");
    }
};
