<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Improvement OptiFleet - Maintenance Request dan Work Order": the Return
 * Parts popup's Condition dropdown for a never-installed ("Unused") return
 * offers "New Good" / "New Faulty" — the existing `condition` enum only had
 * a single UNUSED_NEW value (implicitly always-good). Adds UNUSED_FAULTY
 * alongside it; behaves like USED_FAULTY (PENDING_INSPECTION, never
 * restocks immediately — same Used Sparepart Processing pipeline).
 *
 * Also adds `work_order_part_return_evidence`, mirroring the existing
 * `vehicle_documents` upload pattern exactly: one row per uploaded image,
 * uploaded (and individually removable) before the return is confirmed,
 * then linked to the resulting `work_order_part_returns` row at confirm
 * time — never a plain URL text field, and never trusting the client's
 * filename as the storage path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_condition_check');
        DB::statement(
            'ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_condition_check '.
            "CHECK (condition::text = ANY (ARRAY['UNUSED_NEW','UNUSED_FAULTY','USED_GOOD','USED_FAULTY']::character varying[]))"
        );

        Schema::create('work_order_part_return_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_planned_part_id');
            $table->uuid('work_order_part_return_id')->nullable();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_planned_part_id')->references('id')->on('work_order_planned_parts')->cascadeOnDelete();
            $table->foreign('work_order_part_return_id')->references('id')->on('work_order_part_returns')->cascadeOnDelete();
            $table->index(['tenant_id', 'work_order_planned_part_id']);
            $table->index(['work_order_part_return_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_part_return_evidence');

        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_condition_check');
        DB::statement(
            'ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_condition_check '.
            "CHECK (condition::text = ANY (ARRAY['UNUSED_NEW','USED_GOOD','USED_FAULTY']::character varying[]))"
        );
    }
};
