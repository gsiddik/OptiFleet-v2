<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backing counter for concurrency-safe document numbering, shared by
        // invoices (INV/OPTIFLEET/<year>/<seq>) and contracts
        // (CTR/OPTIFLEET/<year>/<seq>). One row per (type, year), the row is
        // locked (SELECT ... FOR UPDATE) inside a transaction to increment.
        Schema::create('commercial_number_sequences', function (Blueprint $table) {
            $table->string('sequence_key')->primary(); // e.g. "invoice:2027"
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number')->unique();
            $table->uuid('tenant_id');
            $table->uuid('contract_id');
            $table->uuid('subscription_id');
            $table->uuid('billing_id')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->char('currency', 3)->default('IDR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('adjustment', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('outstanding_amount', 14, 2)->default(0);
            $table->enum('status', ['DRAFT', 'ISSUED', 'OUTSTANDING', 'PARTIALLY_PAID', 'PAID', 'OVERDUE', 'VOID'])->default('DRAFT')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->uuid('replaced_by_invoice_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('billing_id')->references('id')->on('billings')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('replaced_by_invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->enum('product_type', ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER']);
            $table->string('product_reference')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('commercial_number_sequences');
    }
};
