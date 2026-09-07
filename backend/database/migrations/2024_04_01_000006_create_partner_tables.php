<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code');
            $table->string('name');
            $table->enum('partner_type', [
                'SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER', 'EXTERNAL_WORKSHOP',
                'TOWING_PROVIDER', 'OTHER_SERVICE_PROVIDER',
            ]);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('payment_terms')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'partner_type', 'status']);
        });

        // Append-only, written automatically off real procurement events
        // (PO issue, goods receipt posting) — Section 25 explicitly scopes
        // Phase 4 to capturing this data, not building KPI dashboards on it.
        Schema::create('partner_performance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('partner_id');
            $table->enum('event_type', [
                'PO_ISSUED', 'DELIVERY_ON_TIME', 'DELIVERY_LATE', 'GOODS_ACCEPTED', 'GOODS_REJECTED', 'RETURN',
            ]);
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->decimal('quantity', 16, 4)->nullable();
            $table->decimal('value', 16, 4)->nullable();
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->index(['tenant_id', 'partner_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_performance_events');
        Schema::dropIfExists('partners');
    }
};
