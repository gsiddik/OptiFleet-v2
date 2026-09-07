<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Section 28: dynamic per-vehicle-category wheel layout rather than
        // an assumed wheel count — a passenger car and a 6-wheel truck get
        // different rows here, both platform-seedable and tenant-extendable.
        Schema::create('wheel_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('vehicle_category_id');
            $table->string('position_code'); // e.g. FL, FR, RL1, RR1, RL2, RR2, SPARE
            $table->string('label');
            $table->unsignedInteger('axle_number')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->cascadeOnDelete();
            $table->unique(['tenant_id', 'vehicle_category_id', 'position_code']);
        });

        Schema::create('tires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('serial_number');
            $table->uuid('product_id');
            $table->string('manufacturer')->nullable();
            $table->string('tire_size')->nullable();
            $table->string('pattern')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 16, 4)->nullable();
            $table->unsignedInteger('warranty_months')->nullable();
            $table->unsignedInteger('warranty_km')->nullable();
            $table->enum('current_status', [
                'IN_STOCK', 'RESERVED', 'INSTALLED', 'IN_USE', 'REMOVED',
                'UNDER_INSPECTION', 'RETREAD', 'SCRAPPED', 'LOST',
            ])->default('IN_STOCK');
            $table->uuid('current_vehicle_id')->nullable();
            $table->string('current_position')->nullable();
            $table->uuid('current_warehouse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('current_vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->foreign('current_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->unique(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'current_status']);
            $table->index(['current_vehicle_id']);
        });

        Schema::create('tire_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->uuid('vehicle_id');
            $table->string('wheel_position');
            $table->timestamp('installed_at');
            $table->decimal('installation_odometer', 12, 2)->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['tire_id']);
            $table->index(['vehicle_id', 'wheel_position']);
        });
        // Only one ACTIVE (not-yet-removed) installation per tire, and per vehicle+position, at a time.
        DB::statement('CREATE UNIQUE INDEX tire_installations_active_tire_unique ON tire_installations (tire_id) WHERE removed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX tire_installations_active_position_unique ON tire_installations (vehicle_id, wheel_position) WHERE removed_at IS NULL');

        Schema::create('tire_rotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->uuid('vehicle_id');
            $table->string('from_position');
            $table->string('to_position');
            $table->decimal('odometer', 12, 2)->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['tire_id']);
        });

        Schema::create('tire_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->decimal('tread_depth_mm', 6, 2)->nullable();
            $table->decimal('pressure_psi', 6, 2)->nullable();
            $table->string('condition')->nullable();
            $table->text('damage')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('evidence')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('inspected_by')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['tire_id']);
        });

        Schema::create('tire_removals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->uuid('tire_installation_id');
            $table->decimal('removal_odometer', 12, 2)->nullable();
            $table->string('removal_reason');
            $table->string('condition')->nullable();
            $table->enum('disposition', ['REUSE', 'RETREAD', 'SCRAP'])->default('REUSE');
            $table->uuid('replaced_by_tire_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('removed_by')->nullable();
            $table->timestamp('removed_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('tire_installation_id')->references('id')->on('tire_installations')->cascadeOnDelete();
            $table->foreign('replaced_by_tire_id')->references('id')->on('tires')->nullOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['tire_id']);
        });

        Schema::create('tire_retreads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->unsignedInteger('cycle_number');
            $table->date('sent_at');
            $table->date('received_at')->nullable();
            $table->uuid('partner_id')->nullable();
            $table->decimal('cost', 16, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->unique(['tire_id', 'cycle_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tire_retreads');
        Schema::dropIfExists('tire_removals');
        Schema::dropIfExists('tire_inspections');
        Schema::dropIfExists('tire_rotations');
        Schema::dropIfExists('tire_installations');
        Schema::dropIfExists('tires');
        Schema::dropIfExists('wheel_configurations');
    }
};
