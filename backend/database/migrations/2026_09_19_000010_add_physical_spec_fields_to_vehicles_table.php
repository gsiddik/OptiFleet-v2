<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation: the VMS Vehicle form lists a full physical
 * specification set (Color, Doors, Seats, Length/Width/Height, Fuel Tank,
 * Engine Capacity, Suspension, Axles, Empty/Load Weight, Wheels, Photo)
 * that Vehicle previously had no equivalent for at all — confirmed by
 * direct field-by-field audit, not merely a labeling difference. All
 * fields are plain descriptive/physical attributes, not policy or
 * business-rule values, so they are added directly. `photo_url` follows
 * the same shallow nullable-string-URL pattern already used for
 * `evidence` fields elsewhere in this codebase (RoadTest, Breakdown,
 * WorkOrderPartReturn, WorkOrderExternalService) rather than building new
 * file-upload infrastructure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('color')->nullable()->after('vehicle_type');
            $table->unsignedTinyInteger('doors')->nullable()->after('color');
            $table->unsignedTinyInteger('seats')->nullable()->after('doors');
            $table->decimal('length_mm', 8, 1)->nullable()->after('seats');
            $table->decimal('width_mm', 8, 1)->nullable()->after('length_mm');
            $table->decimal('height_mm', 8, 1)->nullable()->after('width_mm');
            $table->decimal('fuel_tank_capacity_liters', 8, 2)->nullable()->after('fuel_type');
            $table->decimal('engine_capacity_cc', 10, 1)->nullable()->after('transmission_type');
            $table->string('suspension_type')->nullable()->after('engine_capacity_cc');
            $table->unsignedTinyInteger('axle_count')->nullable()->after('suspension_type');
            $table->decimal('empty_weight_kg', 10, 2)->nullable()->after('axle_count');
            $table->decimal('load_weight_kg', 10, 2)->nullable()->after('empty_weight_kg');
            $table->unsignedTinyInteger('wheel_count')->nullable()->after('load_weight_kg');
            $table->string('photo_url')->nullable()->after('wheel_count');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'color', 'doors', 'seats', 'length_mm', 'width_mm', 'height_mm',
                'fuel_tank_capacity_liters', 'engine_capacity_cc', 'suspension_type',
                'axle_count', 'empty_weight_kg', 'load_weight_kg', 'wheel_count', 'photo_url',
            ]);
        });
    }
};
