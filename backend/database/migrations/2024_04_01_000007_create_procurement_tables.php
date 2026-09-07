<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('pr_number');
            $table->uuid('branch_id')->nullable();
            $table->uuid('workshop_id')->nullable();
            $table->uuid('warehouse_id');
            $table->enum('source_type', ['MANUAL', 'WORK_ORDER', 'REORDER_POINT', 'STOCK_PLANNING'])->default('MANUAL');
            $table->string('source_reference')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->date('required_date')->nullable();
            $table->enum('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT'])->default('MEDIUM');
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'PROCUREMENT', 'REJECTED', 'CANCELLED'])->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['tenant_id', 'pr_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_request_id');
            $table->uuid('product_id');
            $table->decimal('requested_quantity', 16, 4);
            $table->decimal('estimated_unit_price', 16, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['purchase_request_id']);
        });

        Schema::create('rfqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('rfq_number');
            $table->uuid('purchase_request_id')->nullable();
            $table->uuid('warehouse_id');
            $table->date('issue_date')->nullable();
            $table->date('response_deadline')->nullable();
            $table->enum('status', ['DRAFT', 'ISSUED', 'CLOSED', 'CANCELLED'])->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['tenant_id', 'rfq_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('rfq_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 16, 4);
            $table->timestamps();

            $table->foreign('rfq_id')->references('id')->on('rfqs')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['rfq_id']);
        });

        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->uuid('rfq_id');
            $table->uuid('partner_id');
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();

            $table->primary(['rfq_id', 'partner_id']);
            $table->foreign('rfq_id')->references('id')->on('rfqs')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
        });

        Schema::create('vendor_quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('rfq_id');
            $table->uuid('partner_id');
            $table->string('quotation_number')->nullable();
            $table->date('validity_date')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('payment_terms')->nullable();
            $table->decimal('freight_cost', 16, 4)->default(0);
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->decimal('tax_total', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->enum('status', ['SUBMITTED', 'SELECTED', 'REJECTED'])->default('SUBMITTED');
            $table->timestamp('submitted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('rfq_id')->references('id')->on('rfqs')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->unique(['rfq_id', 'partner_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('vendor_quotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vendor_quotation_id');
            $table->uuid('rfq_item_id')->nullable();
            $table->uuid('product_id');
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 16, 4);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('line_total', 16, 4)->default(0);
            $table->timestamps();

            $table->foreign('vendor_quotation_id')->references('id')->on('vendor_quotations')->cascadeOnDelete();
            $table->foreign('rfq_item_id')->references('id')->on('rfq_items')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['vendor_quotation_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('po_number');
            $table->uuid('purchase_request_id')->nullable();
            $table->uuid('vendor_quotation_id')->nullable();
            $table->uuid('partner_id');
            $table->uuid('delivery_warehouse_id');
            $table->enum('status', [
                'DRAFT', 'SUBMITTED', 'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED',
                'RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED',
            ])->default('DRAFT');
            $table->date('order_date')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->decimal('tax_total', 16, 4)->default(0);
            $table->decimal('freight_cost', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->nullOnDelete();
            $table->foreign('vendor_quotation_id')->references('id')->on('vendor_quotations')->nullOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->foreign('delivery_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['tenant_id', 'po_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['partner_id']);
        });

        // Section 51/52: a SELECTED quotation must convert to at most one
        // Purchase Order — this partial unique index is the actual guard
        // against a duplicate-conversion race, not just the service's status
        // check (which two concurrent requests could both pass).
        DB::statement('CREATE UNIQUE INDEX purchase_orders_vendor_quotation_unique ON purchase_orders (vendor_quotation_id) WHERE vendor_quotation_id IS NOT NULL');

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->uuid('product_id');
            $table->decimal('quantity_ordered', 16, 4);
            $table->decimal('quantity_received', 16, 4)->default(0);
            $table->decimal('unit_price', 16, 4);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('line_total', 16, 4)->default(0);
            $table->timestamps();

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['purchase_order_id', 'product_id']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('gr_number');
            $table->uuid('purchase_order_id');
            $table->uuid('warehouse_id');
            $table->uuid('partner_id');
            $table->enum('status', ['DRAFT', 'POSTED'])->default('DRAFT');
            $table->uuid('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->unique(['tenant_id', 'gr_number']);
            $table->index(['purchase_order_id']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('goods_receipt_id');
            $table->uuid('purchase_order_item_id');
            $table->uuid('product_id');
            $table->decimal('quantity_accepted', 16, 4)->default(0);
            $table->decimal('quantity_rejected', 16, 4)->default(0);
            $table->decimal('quantity_damaged', 16, 4)->default(0);
            $table->string('batch_number')->nullable();
            $table->json('serial_numbers')->nullable();
            $table->decimal('unit_cost', 16, 4);
            $table->timestamps();

            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['goods_receipt_id']);
        });

        Schema::create('vendor_invoice_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('partner_id');
            $table->uuid('purchase_order_id')->nullable();
            $table->uuid('goods_receipt_id')->nullable();
            $table->string('vendor_invoice_number');
            $table->date('vendor_invoice_date');
            $table->decimal('amount', 16, 4);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_disk')->nullable();
            $table->enum('status', ['RECEIVED', 'VERIFIED', 'DISPUTED'])->default('RECEIVED');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->nullOnDelete();
            $table->index(['tenant_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_references');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendor_quotation_items');
        Schema::dropIfExists('vendor_quotations');
        Schema::dropIfExists('rfq_vendors');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
