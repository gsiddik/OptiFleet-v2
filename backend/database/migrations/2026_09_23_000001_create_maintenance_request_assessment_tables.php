<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_request_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('maintenance_request_id');
            $table->uuid('assessed_by')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('maintenance_request_id')->references('id')->on('maintenance_requests')->cascadeOnDelete();
            $table->unique('maintenance_request_id', 'mr_assessments_request_unique');
        });

        Schema::create('maintenance_request_inspection_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('maintenance_request_assessment_id');
            $table->enum('group_code', [
                'ENGINE', 'LUBRICATION_SYSTEM', 'CLUTCH_TORQUE_CONVERTER', 'COOLING_SYSTEM',
                'FUEL_SYSTEM', 'TRANSMISSION_SYSTEM', 'EXHAUST_SYSTEM', 'STEERING_SYSTEM',
                'DRIVE_AXLE_ASSEMBLY', 'FRAME_CHASSIS', 'ELECTRICAL_SYSTEM', 'BRAKE_SYSTEM',
                'SUSPENSION_SYSTEM', 'TYRE_WHEEL',
            ]);
            $table->enum('status', ['GOOD', 'ATTENTION', 'REPAIR_REQUIRED', 'CRITICAL_UNSAFE', 'NOT_APPLICABLE']);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('maintenance_request_assessment_id')->references('id')->on('maintenance_request_assessments')->cascadeOnDelete();
            $table->unique(['maintenance_request_assessment_id', 'group_code'], 'mr_inspection_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_request_inspection_groups');
        Schema::dropIfExists('maintenance_request_assessments');
    }
};
