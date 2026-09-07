<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('default_workshop_id')->nullable();
            $table->uuid('vehicle_category_id');
            $table->string('brand');
            $table->string('model');
            $table->string('vehicle_type')->nullable();
            $table->string('registration_number');
            $table->string('vin')->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('transmission_type')->nullable();
            $table->decimal('current_odometer', 12, 2)->default(0);
            $table->decimal('engine_hour', 12, 2)->nullable();
            $table->enum('status', ['ACTIVE', 'IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE', 'DISPOSED'])->default('ACTIVE');
            $table->enum('operational_status', ['AVAILABLE', 'IN_USE', 'ON_HOLD'])->default('AVAILABLE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('default_workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->restrictOnDelete();

            $table->unique(['tenant_id', 'registration_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
        });
        // Nullable-unique fields: Postgres unique indexes already treat NULL
        // as distinct (multiple NULLs allowed), so a plain unique() suffices
        // without a partial-index workaround.
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_tenant_vin_unique UNIQUE (tenant_id, vin)');
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_tenant_chassis_unique UNIQUE (tenant_id, chassis_number)');

        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->uuid('from_branch_id')->nullable();
            $table->uuid('to_branch_id')->nullable();
            $table->uuid('from_workshop_id')->nullable();
            $table->uuid('to_workshop_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('from_branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('to_branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('from_workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->foreign('to_workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::create('vehicle_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->uuid('from_branch_id');
            $table->uuid('to_branch_id');
            $table->uuid('from_workshop_id')->nullable();
            $table->uuid('to_workshop_id')->nullable();
            $table->enum('status', ['DRAFT', 'REQUESTED', 'APPROVED', 'IN_TRANSIT', 'RECEIVED', 'COMPLETED', 'REJECTED', 'CANCELLED'])->default('DRAFT');
            $table->text('reason')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('from_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('to_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('from_workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->foreign('to_workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->enum('document_type', ['REGISTRATION', 'INSPECTION_CERTIFICATE', 'INSURANCE', 'PERMIT', 'WARRANTY', 'OTHER']);
            $table->string('document_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->text('notes')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicle_transfers');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('vehicles');
    }
};
