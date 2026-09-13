<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-09 (final reconciliation): a Rim entity was previously considered and
 * declined ("no evidence describes a reusable rim catalog distinct from a
 * tire's own recorded spec") — that reasoning no longer holds now that the
 * live-VMS analysis document (available for this reconciliation but not for
 * the earlier decision) directly observes Rim as its own real, working
 * master-data screen with its own field set and CRUD. This is a standalone
 * tenant-owned catalog entity, matching Tire's own tenant scoping — it is
 * NOT linked to Tire or WheelConfiguration here, since no source evidence
 * establishes that relationship and inventing one would be fabricating a
 * wheel-layout association not actually observed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code');
            $table->string('brand');
            $table->string('material')->nullable();
            $table->decimal('width_inch', 6, 2)->nullable();
            $table->decimal('diameter_inch', 6, 2)->nullable();
            $table->decimal('disc_thickness_mm', 6, 2)->nullable();
            $table->decimal('offset_mm', 6, 2)->nullable();
            $table->unsignedInteger('bolt_holes')->nullable();
            $table->decimal('bolt_diameter_mm', 6, 2)->nullable();
            $table->decimal('pcd_mm', 6, 2)->nullable();
            $table->decimal('hub_hole_diameter_mm', 6, 2)->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rims');
    }
};
