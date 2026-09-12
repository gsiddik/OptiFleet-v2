<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-07: no capability existed anywhere to record work performed by an
 * external party (towing, third-party workshop, other service provider) on
 * behalf of a Work Order — the Partner types TOWING_PROVIDER and
 * OTHER_SERVICE_PROVIDER existed in the enum but nothing ever referenced
 * them. This is a plain request/complete/cancel record, deliberately with
 * no approval step and no invented cost-accounting entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_external_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->uuid('partner_id');
            $table->text('description');
            $table->string('reference_number')->nullable();
            $table->decimal('cost', 16, 4)->nullable();
            $table->enum('status', ['REQUESTED', 'COMPLETED', 'CANCELLED'])->default('REQUESTED');
            $table->uuid('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_external_services');
    }
};
