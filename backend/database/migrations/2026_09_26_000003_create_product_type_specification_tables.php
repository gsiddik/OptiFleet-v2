<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" (authoritative document):
 * Class-Table-Inheritance spec tables, one per Item Type, each keyed by
 * `product_id` as both primary key and FK (cascade on delete) — the same
 * pattern the existing Product<->Tire catalog/instance split already
 * uses. Every field here is exactly what the document's per-Item-Type
 * specification tables list; instance-only fields (individual Serial
 * Number, actual Batch Number, actual Expiry Date, DOT/Production Date,
 * Purchase Price, Supplier, Current Stock/Condition/Vehicle/Position/
 * Holder, Last Maintenance, Last Calibration) are deliberately absent —
 * those belong to Goods Receipt / the physical asset record, never Item
 * Master. Fields the document marks Mandatory but which already exist as
 * shared columns on `products` (Brand/Manufacturer -> brand, Serialized ->
 * track_serial_number, Batch Tracking -> track_batch) are reused rather
 * than duplicated; per-Item-Type Mandatory/Optional strength for those
 * shared columns is enforced in the request-validation layer, not the
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_spareparts', function (Blueprint $table) {
            $table->uuid('product_id')->primary();
            $table->string('part_number');
            $table->enum('part_type', ['GENUINE', 'OEM', 'OES', 'AFTERMARKET']);
            $table->string('oem_part_number')->nullable();
            $table->json('alternate_part_numbers')->nullable();
            $table->text('specification')->nullable();
            $table->json('applicable_position')->nullable();
            $table->boolean('critical_part')->nullable();
            $table->unsignedInteger('warranty_period_value')->nullable();
            $table->enum('warranty_period_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->unsignedInteger('warranty_mileage_km')->nullable();
            $table->unsignedInteger('shelf_life_value')->nullable();
            $table->enum('shelf_life_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_consumables', function (Blueprint $table) {
            $table->uuid('product_id')->primary();
            $table->string('grade_specification')->nullable();
            $table->decimal('package_size_value', 10, 3)->nullable();
            $table->uuid('package_size_uom_id')->nullable();
            $table->uuid('purchase_uom_id')->nullable();
            $table->decimal('conversion_to_base_uom', 12, 4)->nullable();
            $table->uuid('issue_uom_id')->nullable();
            $table->boolean('track_expiry')->default(false);
            $table->unsignedInteger('shelf_life_value')->nullable();
            $table->enum('shelf_life_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->boolean('is_hazardous')->default(false);
            $table->string('sds_file_path')->nullable();
            $table->string('sds_original_filename')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('package_size_uom_id')->references('id')->on('uoms')->nullOnDelete();
            $table->foreign('purchase_uom_id')->references('id')->on('uoms')->nullOnDelete();
            $table->foreign('issue_uom_id')->references('id')->on('uoms')->nullOnDelete();
        });

        Schema::create('product_consumable_storage_requirements', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('storage_requirement_id');
            $table->timestamps();

            $table->primary(['product_id', 'storage_requirement_id'], 'consumable_storage_req_primary');
            $table->foreign('product_id')->references('product_id')->on('product_consumables')->cascadeOnDelete();
            $table->foreign('storage_requirement_id')->references('id')->on('storage_requirements')->cascadeOnDelete();
        });

        Schema::create('product_rims', function (Blueprint $table) {
            $table->uuid('product_id')->primary();
            $table->string('model')->nullable();
            $table->enum('rim_type', ['STEEL', 'ALLOY', 'FORGED']);
            $table->decimal('diameter_inch', 6, 2);
            $table->decimal('width_inch', 6, 2);
            $table->unsignedInteger('bolt_holes');
            $table->decimal('pcd_mm', 6, 2);
            $table->decimal('center_bore_mm', 6, 2)->nullable();
            $table->decimal('offset_mm', 6, 2)->nullable();
            $table->string('material')->nullable();
            $table->decimal('max_load_kg', 8, 2)->nullable();
            $table->json('compatible_tire_sizes')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_tires', function (Blueprint $table) {
            $table->uuid('product_id')->primary();
            $table->enum('vehicle_group', ['CAR', 'TRUCK_BUS']);
            $table->string('pattern_name');
            $table->unsignedSmallInteger('width_mm');
            $table->unsignedSmallInteger('aspect_ratio_percent');
            $table->enum('construction_type', ['RADIAL', 'BIAS']);
            $table->decimal('rim_diameter_inch', 5, 1);
            $table->enum('tire_type', ['TUBELESS', 'TUBE_TYPE']);
            $table->uuid('single_load_index_id');
            $table->uuid('speed_rating_id');
            $table->uuid('dual_load_index_id')->nullable();
            $table->uuid('ply_rating_id')->nullable();
            $table->uuid('tra_code_id')->nullable();
            $table->uuid('tra_star_rating_id')->nullable();
            // System-derived — recalculated whenever a source FK changes, never a
            // second independently-editable source of truth (same convention as
            // work_orders.estimated_labor_cost_computed).
            $table->string('tire_size_computed')->nullable();
            $table->decimal('single_max_load_kg_computed', 8, 2)->nullable();
            $table->decimal('max_speed_kmh_computed', 6, 2)->nullable();
            $table->decimal('dual_max_load_kg_computed', 8, 2)->nullable();
            $table->string('load_range_computed')->nullable();
            $table->string('tra_profile_computed')->nullable();
            $table->string('purpose_computed')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('single_load_index_id')->references('id')->on('tire_load_indices')->restrictOnDelete();
            $table->foreign('speed_rating_id')->references('id')->on('tire_speed_ratings')->restrictOnDelete();
            $table->foreign('dual_load_index_id')->references('id')->on('tire_load_indices')->restrictOnDelete();
            $table->foreign('ply_rating_id')->references('id')->on('tire_ply_ratings')->restrictOnDelete();
            $table->foreign('tra_code_id')->references('id')->on('tire_tra_codes')->restrictOnDelete();
            $table->foreign('tra_star_rating_id')->references('id')->on('tire_tra_star_ratings')->restrictOnDelete();
        });

        Schema::create('product_tools', function (Blueprint $table) {
            $table->uuid('product_id')->primary();
            $table->string('model')->nullable();
            $table->uuid('tool_type_id');
            $table->text('specification')->nullable();
            $table->boolean('checkout_required')->default(false);
            $table->boolean('calibration_required')->default(false);
            $table->unsignedInteger('calibration_interval_value')->nullable();
            $table->enum('calibration_interval_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->boolean('maintenance_required')->default(false);
            $table->unsignedInteger('maintenance_interval_value')->nullable();
            $table->enum('maintenance_interval_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('tool_type_id')->references('id')->on('tool_types')->restrictOnDelete();
        });

        Schema::create('product_equipment', function (Blueprint $table) {
            $table->uuid('product_id')->primary();
            $table->string('model');
            $table->uuid('equipment_type_id');
            $table->text('specification')->nullable();
            $table->decimal('capacity_value', 12, 3)->nullable();
            $table->uuid('capacity_uom_id')->nullable();
            $table->enum('power_source', ['ELECTRIC', 'HYDRAULIC', 'PNEUMATIC', 'FUEL', 'MANUAL'])->nullable();
            $table->decimal('power_rating_value', 10, 2)->nullable();
            $table->enum('power_rating_unit', ['KW', 'HP'])->nullable();
            $table->unsignedInteger('voltage_v')->nullable();
            $table->boolean('maintenance_required')->default(false);
            $table->unsignedInteger('maintenance_interval_value')->nullable();
            $table->enum('maintenance_interval_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->boolean('inspection_required')->default(false);
            $table->unsignedInteger('inspection_interval_value')->nullable();
            $table->enum('inspection_interval_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->boolean('calibration_required')->default(false);
            $table->unsignedInteger('calibration_interval_value')->nullable();
            $table->enum('calibration_interval_unit', ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'])->nullable();
            $table->boolean('certification_required')->nullable();
            $table->string('certification_type')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('equipment_type_id')->references('id')->on('equipment_types')->restrictOnDelete();
            $table->foreign('capacity_uom_id')->references('id')->on('uoms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_equipment');
        Schema::dropIfExists('product_tools');
        Schema::dropIfExists('product_tires');
        Schema::dropIfExists('product_rims');
        Schema::dropIfExists('product_consumable_storage_requirements');
        Schema::dropIfExists('product_consumables');
        Schema::dropIfExists('product_spareparts');
    }
};
