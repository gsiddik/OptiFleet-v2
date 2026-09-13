<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 (Workshop Invoice and Settlement) — authoritative business decision
 * supplied for this work: a Workshop Invoice is issued EXTERNALLY by the
 * Workshop Partner and RECEIVED/RECORDED by an OptiFleet user. This table
 * therefore models a received external document, not an OptiFleet-issued
 * one — every column is either a plain transcription of what the external
 * invoice states, or OptiFleet's own tracking metadata (who recorded it,
 * when, reconciliation note). There is no "issue" action anywhere in this
 * feature.
 *
 * `external_invoice_number_normalized` backs duplicate detection: trimmed,
 * upper-cased, with internal whitespace collapsed to a single space — a
 * documented, explicit normalization rule (see WorkshopInvoiceService),
 * not a byte-exact match, since the same partner may type "INV-001" and
 * "inv-001" for the same physical document. The partial unique index only
 * applies to non-CANCELLED rows, so a cancelled mis-entry never blocks
 * recording the same external invoice number correctly afterward.
 *
 * `status` here is the settlement-record's own correction/cancellation
 * workflow status — entirely separate from the linked Maintenance Memo's
 * own status (which moves COMPLETED -> BILLED -> PAID; see the memo
 * migration). RECORDED is the normal steady state; CORRECTION_REQUESTED
 * and CANCELLATION_REQUESTED are transient maker-checker in-flight states
 * (see workshop_invoice_corrections / workshop_invoice_cancellations);
 * CANCELLED is terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_external_service_id');
            $table->uuid('work_order_id');
            $table->uuid('partner_id');

            $table->string('external_invoice_number');
            $table->string('external_invoice_number_normalized');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 8)->default('IDR');

            $table->decimal('subtotal', 16, 4)->nullable();
            $table->decimal('tax_total', 16, 4)->nullable();
            $table->decimal('discount_total', 16, 4)->nullable();
            $table->decimal('total_amount', 16, 4);
            $table->json('line_items')->nullable();

            $table->string('partner_reference')->nullable();
            $table->string('returned_memo_attachment_url')->nullable();
            $table->string('invoice_attachment_url')->nullable();
            $table->text('notes')->nullable();
            $table->text('reconciliation_note')->nullable();

            $table->enum('status', ['RECORDED', 'CORRECTION_REQUESTED', 'CANCELLATION_REQUESTED', 'CANCELLED'])->default('RECORDED');

            $table->uuid('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('work_order_external_service_id')->references('id')->on('work_order_external_services')->restrictOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
            $table->index(['tenant_id', 'partner_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Partial unique index: duplicate normalized external invoice numbers are only
        // rejected among a tenant+partner's currently-active (non-CANCELLED) invoices.
        DB::statement(
            'CREATE UNIQUE INDEX workshop_invoices_active_number_unique ON workshop_invoices '.
            '(tenant_id, partner_id, external_invoice_number_normalized) WHERE status <> \'CANCELLED\' AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_invoices');
    }
};
