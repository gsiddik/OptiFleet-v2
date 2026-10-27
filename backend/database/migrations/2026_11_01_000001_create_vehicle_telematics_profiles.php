<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What OptiRadar reports about a vehicle (the telematics group): which device tracks it. Read-only here; OptiRadar owns it.
        Schema::create('vehicle_telematics_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('vehicle_id');
            $t->string('device_ref', 120)->nullable();
            $t->string('device_name', 150)->nullable();
            $t->unsignedBigInteger('source_version');
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $t->unique(['tenant_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_telematics_profiles');
    }
};
