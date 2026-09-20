<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice": evolves the minimal WorkOrderExternalReference placeholder
 * (one row per External Work Order, no persisted lifecycle) into the full
 * External Work Order Invoice aggregate the new document requires —
 * New External WO -> Delivered -> In Progress -> Cancelled/Billed -> Paid,
 * plus its own Work Authorization sub-status and WAL snapshot fields.
 *
 * Deliberately NOT named/tabled as "workshop_invoice(s)" — that name and
 * table are already used by the pre-existing, unrelated R1 feature
 * (an externally-issued invoice OptiFleet records for a Partner-performed
 * towing/3rd-party service on an otherwise INTERNAL Work Order). This
 * migration only renames/extends work_order_external_references, which
 * has exactly one backend consumer and zero frontend consumers as of this
 * migration, so the rename is safe.
 *
 * File attachments (Acknowledgement copy, Completed Work Order copy,
 * Vendor Invoice copy, Payment Proof) are normalized into a small child
 * table keyed by file_role rather than inlined as 4x(disk/path/mime/size)
 * column groups on the header row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('work_order_external_references', 'work_order_external_invoices');

        Schema::table('work_order_external_invoices', function (Blueprint $table) {
            $table->enum('status', ['NEW_EXTERNAL_WO', 'DELIVERED', 'IN_PROGRESS', 'CANCELLED', 'BILLED', 'PAID'])
                ->default('NEW_EXTERNAL_WO')->after('work_order_id');
            $table->enum('work_authorization_status', ['NOT_GENERATED', 'GENERATED', 'ACKNOWLEDGED'])
                ->default('NOT_GENERATED')->after('status');

            // Work Authorization Letter — snapshot fields, frozen at generation time so a later
            // change to the vendor/vehicle/company master data never alters a historical WAL.
            $table->string('wal_number')->nullable();
            $table->date('wal_issue_date')->nullable();
            $table->uuid('wal_workshop_partner_id')->nullable();
            $table->string('wal_workshop_name')->nullable();
            $table->text('wal_workshop_address')->nullable();
            $table->string('wal_workshop_pic')->nullable();
            $table->string('wal_workshop_phone')->nullable();
            $table->string('wal_vehicle_unit_number')->nullable();
            $table->string('wal_vehicle_registration_number')->nullable();
            $table->string('wal_vehicle_make_model')->nullable();
            $table->decimal('wal_vehicle_odometer', 10, 2)->nullable();
            $table->string('wal_company_name')->nullable();
            $table->unsignedInteger('wal_revision')->default(0);
            $table->uuid('wal_generated_by')->nullable();
            $table->timestamp('wal_generated_at')->nullable();

            // Deliver
            $table->uuid('delivered_by')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Acknowledge
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            // Complete / Billing
            $table->date('vendor_invoice_date')->nullable();
            $table->decimal('vendor_invoice_amount', 16, 4)->nullable();
            $table->string('payment_term')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Settlement
            $table->date('payment_date')->nullable();
            $table->decimal('paid_amount', 16, 4)->nullable();
            $table->uuid('settled_by')->nullable();
            $table->timestamp('settled_at')->nullable();

            // Cancel
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Section 11: a Work Order must never accidentally accumulate more than one active
            // External Invoice — finalize() already firstOrCreate()s by work_order_id, this
            // constraint makes that guarantee hold at the database level too.
            $table->unique('work_order_id', 'wo_external_invoices_wo_unique');
            $table->foreign('wal_workshop_partner_id', 'wo_ext_invoices_wal_partner_fk')->references('id')->on('partners')->nullOnDelete();
            $table->index(['tenant_id', 'status'], 'wo_ext_invoices_tenant_status_idx');
        });

        Schema::create('work_order_external_invoice_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_invoice_id');
            $table->enum('file_role', ['ACKNOWLEDGEMENT', 'COMPLETED_WORK_ORDER', 'VENDOR_INVOICE', 'PAYMENT_PROOF']);
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('external_invoice_id', 'wo_ext_invoice_files_invoice_fk')->references('id')->on('work_order_external_invoices')->cascadeOnDelete();
            $table->unique(['external_invoice_id', 'file_role'], 'wo_ext_invoice_files_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_external_invoice_files');

        Schema::table('work_order_external_invoices', function (Blueprint $table) {
            $table->dropForeign('wo_ext_invoices_wal_partner_fk');
            $table->dropUnique('wo_external_invoices_wo_unique');
            $table->dropIndex('wo_ext_invoices_tenant_status_idx');
            $table->dropColumn([
                'status', 'work_authorization_status',
                'wal_number', 'wal_issue_date', 'wal_workshop_partner_id', 'wal_workshop_name', 'wal_workshop_address',
                'wal_workshop_pic', 'wal_workshop_phone', 'wal_vehicle_unit_number', 'wal_vehicle_registration_number',
                'wal_vehicle_make_model', 'wal_vehicle_odometer', 'wal_company_name', 'wal_revision', 'wal_generated_by', 'wal_generated_at',
                'delivered_by', 'delivered_at', 'acknowledged_by', 'acknowledged_at',
                'vendor_invoice_date', 'vendor_invoice_amount', 'payment_term', 'completed_by', 'completed_at',
                'payment_date', 'paid_amount', 'settled_by', 'settled_at',
                'cancelled_by', 'cancelled_at', 'cancellation_reason',
            ]);
        });

        Schema::rename('work_order_external_invoices', 'work_order_external_references');
    }
};
