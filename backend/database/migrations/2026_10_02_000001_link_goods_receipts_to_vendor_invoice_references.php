<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods Receipt becomes the entry point for Vendor Invoice References, and one invoice can
 * cover several receipts of the same Purchase Order:
 *
 *   goods_receipts.vendor_invoice_reference_id  -> the invoice that GR was received against
 *                                                  (many GRs may share one invoice).
 *   vendor_invoice_references: terms_of_payment_days (working days), due_date (derived once
 *   from invoice date + terms, stored), document metadata, origin, created_by.
 *
 * Additive and non-destructive. Legacy references keep origin MANUAL and their data; a legacy
 * reference already pointing at a GR (vendor_invoice_references.goods_receipt_id) is linked
 * from that GR. Vendor + invoice number is unique per tenant for GR-captured invoices (legacy
 * manual rows are not constrained, so existing duplicates cannot block the migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_invoice_references', function (Blueprint $table) {
            $table->string('origin', 20)->default('MANUAL')->after('status');
            $table->unsignedSmallInteger('terms_of_payment_days')->nullable()->after('amount');
            $table->date('due_date')->nullable()->after('terms_of_payment_days');
            $table->string('attachment_original_name')->nullable()->after('attachment_disk');
            $table->string('attachment_mime_type', 100)->nullable()->after('attachment_original_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime_type');
            $table->uuid('created_by')->nullable()->after('notes');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'due_date']);
        });
        DB::statement("ALTER TABLE vendor_invoice_references ADD CONSTRAINT vendor_invoice_references_origin_check CHECK (origin IN ('MANUAL','GOODS_RECEIPT'))");
        DB::statement("CREATE UNIQUE INDEX vendor_invoice_references_gr_vendor_number_unique ON vendor_invoice_references (tenant_id, partner_id, UPPER(vendor_invoice_number)) WHERE origin = 'GOODS_RECEIPT'");

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->uuid('vendor_invoice_reference_id')->nullable()->after('partner_id');
            $table->foreign('vendor_invoice_reference_id')->references('id')->on('vendor_invoice_references')->restrictOnDelete();
            $table->index('vendor_invoice_reference_id');
        });

        // Legacy link (invoice -> one GR) becomes the GR -> invoice link.
        DB::statement('UPDATE goods_receipts gr SET vendor_invoice_reference_id = vir.id
            FROM vendor_invoice_references vir
            WHERE vir.goods_receipt_id = gr.id AND vir.tenant_id = gr.tenant_id AND gr.vendor_invoice_reference_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['vendor_invoice_reference_id']);
            $table->dropIndex(['vendor_invoice_reference_id']);
            $table->dropColumn('vendor_invoice_reference_id');
        });
        DB::statement('DROP INDEX IF EXISTS vendor_invoice_references_gr_vendor_number_unique');
        DB::statement('ALTER TABLE vendor_invoice_references DROP CONSTRAINT IF EXISTS vendor_invoice_references_origin_check');
        Schema::table('vendor_invoice_references', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['tenant_id', 'due_date']);
            $table->dropColumn(['origin', 'terms_of_payment_days', 'due_date', 'attachment_original_name', 'attachment_mime_type', 'attachment_size', 'created_by']);
        });
    }
};
