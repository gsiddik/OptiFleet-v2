<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('component_group_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('asset_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 16, 4)->nullable();
            $table->enum('current_status', [
                'IN_STOCK', 'INSTALLED', 'ACTIVE', 'FAILED', 'REMOVED', 'UNDER_REPAIR', 'RECONDITIONED', 'SCRAPPED',
            ])->default('IN_STOCK');
            $table->uuid('current_vehicle_id')->nullable();
            $table->uuid('current_warehouse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
            $table->foreign('current_vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->foreign('current_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->index(['tenant_id', 'current_status']);
            $table->index(['current_vehicle_id']);
        });
        DB::statement('CREATE UNIQUE INDEX component_assets_serial_unique ON component_assets (tenant_id, serial_number) WHERE serial_number IS NOT NULL AND deleted_at IS NULL');

        Schema::create('component_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('component_asset_id');
            $table->uuid('vehicle_id');
            $table->string('position_location')->nullable();
            $table->decimal('installation_odometer', 12, 2)->nullable();
            $table->timestamp('installed_at');
            $table->uuid('work_order_id')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('component_asset_id')->references('id')->on('component_assets')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['component_asset_id']);
            $table->index(['vehicle_id']);
        });
        // Section 37: a serialized component can never be actively installed on more than one vehicle at once.
        DB::statement('CREATE UNIQUE INDEX component_installations_active_asset_unique ON component_installations (component_asset_id) WHERE removed_at IS NULL');

        Schema::create('component_removals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('component_asset_id');
            $table->uuid('component_installation_id');
            $table->decimal('removal_odometer', 12, 2)->nullable();
            $table->string('removal_reason');
            $table->string('condition')->nullable();
            $table->enum('disposition', ['REUSE', 'REPAIR', 'SCRAP'])->default('REUSE');
            $table->uuid('replaced_by_asset_id')->nullable();
            $table->text('diagnosis_note')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('removed_by')->nullable();
            $table->timestamp('removed_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('component_asset_id')->references('id')->on('component_assets')->cascadeOnDelete();
            $table->foreign('component_installation_id')->references('id')->on('component_installations')->cascadeOnDelete();
            $table->foreign('replaced_by_asset_id')->references('id')->on('component_assets')->nullOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['component_asset_id']);
        });

        Schema::create('component_repairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('component_asset_id');
            $table->text('description');
            $table->uuid('work_order_id')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->decimal('cost', 16, 4)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->enum('outcome', ['RECONDITIONED', 'SCRAPPED', 'RETURNED_TO_SERVICE'])->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('component_asset_id')->references('id')->on('component_assets')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['component_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_repairs');
        Schema::dropIfExists('component_removals');
        Schema::dropIfExists('component_installations');
        Schema::dropIfExists('component_assets');
    }
};
