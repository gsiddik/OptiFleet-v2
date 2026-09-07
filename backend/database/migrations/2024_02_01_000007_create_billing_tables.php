<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->uuid('tenant_id');
            $table->uuid('contract_id');
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('adjustment', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->enum('status', ['DRAFT', 'GENERATED', 'INVOICED', 'PAID', 'PARTIALLY_PAID', 'PAST_DUE', 'CANCELLED'])->default('DRAFT')->index();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            // Idempotency: never generate two billings for the same
            // subscription + period.
            $table->unique(['subscription_id', 'billing_period_start', 'billing_period_end'], 'billings_period_unique');
        });

        Schema::create('billing_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('billing_id');
            $table->uuid('contract_item_id')->nullable();
            $table->enum('product_type', ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER']);
            $table->string('product_reference')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('proration_factor', 6, 4)->nullable();
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->foreign('billing_id')->references('id')->on('billings')->cascadeOnDelete();
            $table->foreign('contract_item_id')->references('id')->on('contract_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_items');
        Schema::dropIfExists('billings');
    }
};
