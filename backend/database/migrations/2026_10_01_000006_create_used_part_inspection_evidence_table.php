<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Used Sparepart Processing evidence photos: uploaded JPG/PNG files (max 3 MB) stored on the
 * private disk and streamed through an authorized endpoint, replacing the free-text evidence URL
 * for new inspections. Historical `inspection_evidence` URL values are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_part_inspection_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_part_return_id');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_part_return_id')->references('id')->on('work_order_part_returns')->cascadeOnDelete();
            $table->index(['tenant_id', 'work_order_part_return_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_part_inspection_evidence');
    }
};
