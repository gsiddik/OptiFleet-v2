<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->enum('coverage_basis', ['DATE', 'MILEAGE', 'ENGINE_HOUR', 'COMBINATION']);
            $table->unsignedInteger('duration_months')->nullable();
            $table->unsignedInteger('duration_km')->nullable();
            $table->unsignedInteger('duration_engine_hours')->nullable();
            $table->unsignedInteger('tolerance_days')->default(0);
            $table->unsignedInteger('tolerance_km')->default(0);
            $table->unsignedInteger('tolerance_engine_hours')->default(0);
            $table->date('starts_at');
            $table->decimal('start_odometer', 12, 2)->nullable();
            $table->decimal('start_engine_hour', 12, 2)->nullable();
            $table->uuid('partner_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->uuid('component_asset_id')->nullable();
            $table->uuid('tire_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'VOID'])->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('component_asset_id')->references('id')->on('component_assets')->nullOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->nullOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('claim_number');
            $table->uuid('warranty_id')->nullable();
            $table->uuid('partner_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->uuid('component_asset_id')->nullable();
            $table->uuid('tire_id')->nullable();
            $table->uuid('vehicle_id');
            $table->uuid('work_order_id')->nullable();
            $table->date('failure_date');
            $table->decimal('failure_odometer', 12, 2)->nullable();
            $table->decimal('claim_amount', 16, 4)->nullable();
            $table->string('evidence')->nullable();
            $table->text('reason');
            $table->enum('status', [
                'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED',
                'REPLACEMENT', 'REPAIR', 'SETTLED', 'CLOSED',
            ])->default('DRAFT');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warranty_id')->references('id')->on('warranties')->nullOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('component_asset_id')->references('id')->on('component_assets')->nullOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->nullOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranties');
    }
};
