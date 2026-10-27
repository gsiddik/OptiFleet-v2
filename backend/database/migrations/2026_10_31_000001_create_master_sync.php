<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle master data shared with the other platforms (OptiNexus docs/integration/MASTER_DATA_SYNC.md): a vehicle announced by
 * another platform exists here as a DRAFT until the tenant's staff complete it, and the transport data OptiDeli owns is kept
 * beside the asset (never inside it).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['branch_id', 'vehicle_category_id', 'brand', 'model'] as $column) {
            DB::statement("ALTER TABLE vehicles ALTER COLUMN {$column} DROP NOT NULL");
        }
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT vehicles_status_check');
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_status_check CHECK (status IN ('DRAFT','ACTIVE','IN_MAINTENANCE','BREAKDOWN','OUT_OF_SERVICE','INACTIVE','DISPOSED'))");
        Schema::table('vehicles', fn (Blueprint $t) => $t->string('source_application', 20)->default('OPTIFLEET'));
        // Only a draft may lack what an asset needs; completing it fills these in.
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_complete_unless_draft_check CHECK (status = 'DRAFT' OR (branch_id IS NOT NULL AND vehicle_category_id IS NOT NULL AND brand IS NOT NULL AND model IS NOT NULL))");

        Schema::create('vehicle_transport_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('vehicle_id');
            $t->string('carrier_type_code', 80)->nullable();
            $t->unsignedBigInteger('capacity_g')->nullable();
            $t->unsignedInteger('length_mm')->nullable();
            $t->unsignedInteger('width_mm')->nullable();
            $t->unsignedInteger('height_mm')->nullable();
            $t->unsignedBigInteger('source_version');
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $t->unique(['tenant_id', 'vehicle_id']);
        });

        // The newest version applied per vehicle and producing platform, so an old delivery never overwrites a newer one.
        Schema::create('master_versions', function (Blueprint $t) {
            $t->uuid('tenant_id');
            $t->string('registration_key', 60);
            $t->string('source', 20);
            $t->unsignedBigInteger('version');
            $t->primary(['tenant_id', 'registration_key', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_versions');
        Schema::dropIfExists('vehicle_transport_profiles');
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_complete_unless_draft_check');
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn('source_application'));
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT vehicles_status_check');
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_status_check CHECK (status IN ('ACTIVE','IN_MAINTENANCE','BREAKDOWN','OUT_OF_SERVICE','INACTIVE','DISPOSED'))");
    }
};
