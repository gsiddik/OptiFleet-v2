<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R1: maker-checker correction workflow for a recorded Workshop Invoice.
 * `previous_values`/`requested_values` are field-level JSON snapshots taken
 * at request time — the original values are never overwritten in place
 * (they live here, permanently, even after approval changes the live
 * `workshop_invoices` row), satisfying "never silently overwrite the
 * original approved or billed record" without needing a full bitemporal
 * versioning system this feature does not otherwise call for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_invoice_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workshop_invoice_id');
            $table->json('previous_values');
            $table->json('requested_values');
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
        Schema::dropIfExists('workshop_invoice_corrections');
    }
};
