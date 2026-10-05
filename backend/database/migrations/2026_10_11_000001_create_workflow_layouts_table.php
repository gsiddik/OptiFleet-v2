<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visual Workflow Builder layout (additive): where each status card sits on the canvas, per
 * tenant workflow version. Presentation only — the runtime never reads it, so moving a card never
 * creates a new workflow version and never changes behaviour. A version without a layout row is
 * shown with a deterministic auto-layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_layouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('configuration_version_id')->unique();
            $table->jsonb('positions');
            $table->jsonb('viewport')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('configuration_version_id')->references('id')->on('configuration_versions')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_layouts');
    }
};
