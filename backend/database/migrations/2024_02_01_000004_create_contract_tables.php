<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('contract_number')->unique();
            $table->uuid('tenant_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('billing_cycle', ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL', 'CUSTOM']);
            $table->unsignedInteger('payment_terms_days')->default(14);
            $table->unsignedInteger('grace_period_days')->default(7);
            $table->char('currency', 3)->default('IDR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->enum('status', [
                'DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'ACTIVE',
                'EXPIRING', 'EXPIRED', 'REJECTED', 'CANCELLED', 'TERMINATED',
            ])->default('DRAFT')->index();
            $table->boolean('activation_requires_payment')->default(true);
            $table->uuid('renewed_from_contract_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreign('renewed_from_contract_id')->references('id')->on('contracts')->nullOnDelete();
        });

        Schema::create('contract_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->enum('product_type', ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER']);
            $table->string('product_reference')->nullable(); // bundle/module code, or capacity resource_type
            $table->uuid('bundle_version_id')->nullable();
            $table->uuid('pricing_version_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('final_amount', 14, 2);
            $table->enum('billing_frequency', ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL', 'CUSTOM']);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('bundle_version_id')->references('id')->on('bundle_versions')->nullOnDelete();
            $table->foreign('pricing_version_id')->references('id')->on('pricing_versions')->nullOnDelete();
            $table->index(['contract_id']);
        });

        Schema::create('contract_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->uuid('approver_user_id');
            $table->enum('status', ['APPROVED', 'REJECTED']);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('approver_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->unsignedInteger('amendment_number');
            $table->enum('status', ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'APPLIED'])->default('DRAFT')->index();
            $table->text('reason')->nullable();
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot')->nullable();
            $table->date('effective_date');
            $table->decimal('proration_amount', 14, 2)->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['contract_id', 'amendment_number']);
        });

        Schema::create('contract_amendment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_amendment_id');
            $table->enum('action', ['ADD', 'REMOVE']);
            $table->enum('product_type', ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER']);
            $table->string('product_reference')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('final_amount', 14, 2)->default(0);
            $table->uuid('contract_item_id')->nullable(); // set for REMOVE, and for the created item on ADD
            $table->timestamps();

            $table->foreign('contract_amendment_id')->references('id')->on('contract_amendments')->cascadeOnDelete();
            $table->foreign('contract_item_id')->references('id')->on('contract_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_amendment_items');
        Schema::dropIfExists('contract_amendments');
        Schema::dropIfExists('contract_approvals');
        Schema::dropIfExists('contract_items');
        Schema::dropIfExists('contracts');
    }
};
