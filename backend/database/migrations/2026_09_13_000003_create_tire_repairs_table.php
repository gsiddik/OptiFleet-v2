<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E (G-27): REPAIR is a distinct lifecycle from RETREAD, not a
 * relabeled disposition sharing tire_retreads — it gets its own table,
 * its own independent cycle-number sequence, and the same
 * send/receive/final-inspect/approve governance trail as retread cycles
 * (see 2026_09_13_000002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_repairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->unsignedInteger('cycle_number');
            $table->date('sent_at');
            $table->uuid('sent_by')->nullable();
            $table->uuid('partner_id');
            $table->decimal('cost', 16, 4)->nullable();
            $table->text('notes')->nullable();
            $table->date('received_at')->nullable();
            $table->uuid('received_by')->nullable();
            $table->enum('status', ['SENT', 'RECEIVED', 'FINAL_INSPECTED', 'APPROVED', 'REJECTED'])->default('SENT');
            $table->uuid('final_inspected_by')->nullable();
            $table->timestamp('final_inspected_at')->nullable();
            $table->enum('final_inspection_result', ['SAFE', 'UNSAFE'])->nullable();
            $table->text('final_inspection_notes')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->enum('approval_disposition', ['RETURN_TO_SERVICE', 'SCRAP', 'QUARANTINE'])->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->unique(['tire_id', 'cycle_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tire_repairs');
    }
};
