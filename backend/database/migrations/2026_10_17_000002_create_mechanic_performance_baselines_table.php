<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanic Performance baseline (owner decision): one expected work time in hours per tenant and
 * maintenance type, entered by a user holding mechanic_baseline.manage. No row = "baseline not set"
 * (never a default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_performance_baselines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('maintenance_type', 32);
            $table->decimal('baseline_hours', 8, 2);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'maintenance_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_performance_baselines');
    }
};
