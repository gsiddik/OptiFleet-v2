<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who drove a vehicle, as OptiDeli reports it: one row per fact (a trip that left, a day worked). A snapshot of the driver, never the
        // driver register (OptiDeli owns that) and never the full licence number. Matched to a vehicle by plate key when read.
        Schema::create('vehicle_driver_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('registration_key', 60);
            $t->string('reference_type', 20);
            $t->uuid('reference_id');
            $t->string('reference_number', 60)->nullable();
            $t->timestamp('assigned_at');
            $t->string('driver_name', 150);
            $t->string('driver_phone', 40)->nullable();
            $t->string('license_masked', 20)->nullable();
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->unique(['tenant_id', 'reference_type', 'reference_id']);
            $t->index(['tenant_id', 'registration_key', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_driver_assignments');
    }
};
