<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contract_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('next_billing_date');
            $table->date('grace_period_end')->nullable();
            $table->enum('status', [
                'PENDING', 'ACTIVE', 'EXPIRING', 'PAST_DUE',
                'GRACE_PERIOD', 'SUSPENDED', 'EXPIRED', 'CANCELLED',
            ])->default('PENDING')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->unique(['contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
