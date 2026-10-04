<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tire Operations: a planned tire job (Replacement / Rotation / Inspection) on one vehicle,
 * executed through the Work Order created with it. Additive only — no existing table or row is
 * changed; work_order_part_requests only gains a nullable link to the operation that generated it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->uuid('work_order_id')->nullable()->unique();
            // Snapshot of the configuration the positions were chosen from.
            $table->uuid('wheel_configuration_version_id');
            $table->string('config_code', 50);
            $table->string('operation_type', 20);
            $table->timestampTz('operated_at');
            $table->decimal('odometer', 12, 2);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('vehicle_id')->references('id')->on('vehicles');
            $table->foreign('work_order_id')->references('id')->on('work_orders');
            $table->foreign('wheel_configuration_version_id')->references('id')->on('wheel_configuration_versions');
            $table->index(['tenant_id', 'created_at'], 'tire_operations_tenant_created_idx');
            $table->index(['tenant_id', 'vehicle_id'], 'tire_operations_tenant_vehicle_idx');
        });
        DB::statement("ALTER TABLE tire_operations ADD CONSTRAINT tire_operations_type_check CHECK (operation_type IN ('REPLACEMENT', 'ROTATION', 'INSPECTION'))");
        DB::statement('ALTER TABLE tire_operations ADD CONSTRAINT tire_operations_odometer_check CHECK (odometer >= 0)');

        Schema::create('tire_operation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_operation_id');
            $table->string('position_code', 20);
            // The tire on the position when the operation was planned.
            $table->uuid('tire_id');
            // Replacement: the serial that will be installed instead.
            $table->uuid('replacement_tire_id')->nullable();
            // Rotation: positions with the same pair number swap with each other.
            $table->unsignedSmallInteger('pair_number')->nullable();
            // Measurement taken during the operation (inspection), never fabricated.
            $table->decimal('tread_depth_mm', 5, 2)->nullable();
            $table->timestampTz('applied_at')->nullable();
            // Set when the replacement serial is no longer held (installed or operation cancelled).
            $table->timestampTz('replacement_released_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('tire_operation_id')->references('id')->on('tire_operations');
            $table->foreign('tire_id')->references('id')->on('tires');
            $table->foreign('replacement_tire_id')->references('id')->on('tires');
            $table->unique(['tire_operation_id', 'position_code'], 'tire_operation_items_position_unique');
            $table->index('tire_id', 'tire_operation_items_tire_idx');
        });
        // A serial can be held as "Replacing With" by only one open operation at a time.
        DB::statement('CREATE UNIQUE INDEX tire_operation_items_open_replacement_unique ON tire_operation_items (replacement_tire_id) WHERE replacement_tire_id IS NOT NULL AND replacement_released_at IS NULL');
        DB::statement('ALTER TABLE tire_operation_items ADD CONSTRAINT tire_operation_items_tread_check CHECK (tread_depth_mm IS NULL OR tread_depth_mm >= 0)');

        Schema::table('work_order_part_requests', function (Blueprint $table) {
            $table->uuid('tire_operation_id')->nullable()->after('work_order_id');
            $table->foreign('tire_operation_id')->references('id')->on('tire_operations');
            $table->index('tire_operation_id', 'wopr_tire_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_part_requests', function (Blueprint $table) {
            $table->dropForeign(['tire_operation_id']);
            $table->dropIndex('wopr_tire_operation_idx');
            $table->dropColumn('tire_operation_id');
        });
        Schema::dropIfExists('tire_operation_items');
        Schema::dropIfExists('tire_operations');
    }
};
