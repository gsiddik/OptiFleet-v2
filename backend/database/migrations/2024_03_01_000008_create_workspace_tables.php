<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workshop_id');
            $table->string('code');
            $table->string('name');
            $table->enum('workspace_type', [
                'GENERAL_SERVICE_BAY', 'HEAVY_VEHICLE_BAY', 'INSPECTION_BAY', 'ELECTRICAL_BAY',
                'TIRE_BAY', 'QC_BAY', 'WASHING_BAY', 'PARKING_LOT', 'HOLDING_AREA', 'OTHER',
            ])->default('GENERAL_SERVICE_BAY');
            $table->enum('status', ['AVAILABLE', 'RESERVED', 'OCCUPIED', 'BLOCKED', 'UNDER_MAINTENANCE', 'INACTIVE'])->default('AVAILABLE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workshop_id')->references('id')->on('workshops')->cascadeOnDelete();
            $table->unique(['tenant_id', 'workshop_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('workspace_vehicle_categories', function (Blueprint $table) {
            $table->uuid('workspace_id');
            $table->uuid('vehicle_category_id');
            $table->timestamps();

            $table->primary(['workspace_id', 'vehicle_category_id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->cascadeOnDelete();
        });

        Schema::create('workspace_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workspace_id');
            $table->uuid('work_order_id')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->enum('status', ['RESERVED', 'ACTIVE', 'COMPLETED', 'CANCELLED'])->default('RESERVED');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->index(['workspace_id', 'status', 'start_at', 'end_at']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', fn (Blueprint $t) => $t->dropForeign(['workspace_id']));
        Schema::dropIfExists('workspace_reservations');
        Schema::dropIfExists('workspace_vehicle_categories');
        Schema::dropIfExists('workspaces');
    }
};
