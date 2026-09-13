<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R1: maker-checker cancellation workflow for a recorded Workshop Invoice, mirroring workshop_invoice_corrections. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_invoice_cancellations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workshop_invoice_id');
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->uuid('requested_by');
            $table->timestamp('requested_at');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->foreign('workshop_invoice_id')->references('id')->on('workshop_invoices')->cascadeOnDelete();
            $table->index(['tenant_id', 'workshop_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_invoice_cancellations');
    }
};
