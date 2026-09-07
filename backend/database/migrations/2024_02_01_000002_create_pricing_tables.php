<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // What this price list is for: a specific module, bundle, add-on
            // module, or a capacity resource_type (vehicle/user/branch/...).
            $table->enum('priceable_type', ['MODULE', 'BUNDLE', 'ADD_ON', 'CAPACITY']);
            $table->string('priceable_code'); // module/bundle code, or capacity resource_type
            $table->enum('pricing_method', ['FLAT', 'PER_VEHICLE', 'PER_USER', 'PER_BRANCH', 'PER_WORKSHOP', 'PER_WAREHOUSE', 'TIERED', 'CUSTOM']);
            $table->enum('billing_frequency', ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL', 'CUSTOM']);
            $table->char('currency', 3)->default('IDR');
            $table->enum('status', ['DRAFT', 'ACTIVE', 'ARCHIVED'])->default('DRAFT')->index();
            $table->timestamps();

            $table->unique(['priceable_type', 'priceable_code', 'billing_frequency']);
        });

        // Multiple time-boxed price points per pricing product — never
        // overwritten, so historical contracts keep referencing the exact
        // version they were priced against.
        Schema::create('pricing_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pricing_id');
            $table->unsignedInteger('version_number');
            $table->decimal('amount', 14, 2);
            $table->jsonb('tiers')->nullable(); // for TIERED method: [{"from":1,"to":10,"amount":...}, ...]
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'EXPIRED'])->default('DRAFT')->index();
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamps();

            $table->foreign('pricing_id')->references('id')->on('pricings')->cascadeOnDelete();
            $table->foreign('published_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['pricing_id', 'version_number']);
            $table->index(['pricing_id', 'status', 'effective_from', 'effective_until']);
        });

        // Negotiated tenant-specific override of a pricing product.
        Schema::create('tenant_custom_pricings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('pricing_id');
            $table->decimal('amount', 14, 2);
            $table->jsonb('tiers')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('reason')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('pricing_id')->references('id')->on('pricings')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'pricing_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_custom_pricings');
        Schema::dropIfExists('pricing_versions');
        Schema::dropIfExists('pricings');
    }
};
