<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R1: payment evidence recorded against a Workshop Invoice. The business
 * decision supplied for this work does not describe partial payment, so
 * this is deliberately a one-to-one relationship (unique on
 * workshop_invoice_id) with `paid_amount` validated against the invoice's
 * `total_amount` in WorkshopInvoiceService — not stubbed as a
 * multi-instalment ledger this feature was never asked to build.
 * `evidence_url` is NOT nullable: payment evidence is mandatory per the
 * business requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workshop_invoice_id')->unique();
            $table->date('payment_date');
            $table->decimal('paid_amount', 16, 4);
            $table->string('payment_method')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('evidence_url');
            $table->text('notes')->nullable();
            $table->uuid('uploaded_by');
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->foreign('workshop_invoice_id')->references('id')->on('workshop_invoices')->cascadeOnDelete();
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_invoice_payments');
    }
};
