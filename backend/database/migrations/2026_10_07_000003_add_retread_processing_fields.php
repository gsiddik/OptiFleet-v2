<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retread / repair processing from Used Tire Management (Open Cycle → Receive → Tire Inspection):
 *
 *  tire_cycle_photos                       1–3 photos taken when a cycle is opened (private disk)
 *  tire_retreads / tire_repairs
 *    tire_used_inspection_id               the post-cycle Tire Inspection (re-inspection)
 *    final_status                          its approved disposition (REUSE / REPAIR / RETREAD / HOLD / SCRAP)
 *
 * The cycle states stay the existing ones: SENT (in process) → RECEIVED → FINAL_INSPECTED
 * (re-inspection submitted) → APPROVED (completed; next disposition = final_status). `cost` is
 * the Estimated Price entered when the cycle is opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_cycle_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('cycle_type', 10);
            $table->uuid('cycle_id');
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['cycle_type', 'cycle_id']);
        });
        DB::statement("ALTER TABLE tire_cycle_photos ADD CONSTRAINT tire_cycle_photos_type_check CHECK (cycle_type IN ('RETREAD', 'REPAIR'))");

        foreach (['tire_retreads', 'tire_repairs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('tire_used_inspection_id')->nullable();
                $t->string('final_status', 10)->nullable();
                $t->foreign('tire_used_inspection_id')->references('id')->on('tire_used_inspections')->nullOnDelete();
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_final_status_check CHECK (final_status IS NULL OR final_status IN ('REUSE', 'REPAIR', 'RETREAD', 'HOLD', 'SCRAP'))");
        }
    }

    public function down(): void
    {
        foreach (['tire_retreads', 'tire_repairs'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_final_status_check");
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['tire_used_inspection_id']);
                $t->dropColumn(['tire_used_inspection_id', 'final_status']);
            });
        }
        Schema::dropIfExists('tire_cycle_photos');
    }
};
