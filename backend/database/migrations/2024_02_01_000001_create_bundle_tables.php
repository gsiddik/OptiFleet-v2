<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'])->default('DRAFT')->index();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Current (mutable while DRAFT) module composition. Pure pivot: no
        // surrogate id since rows are written via BelongsToMany::sync(),
        // which does not populate an auto-generated uuid primary key.
        Schema::create('bundle_modules', function (Blueprint $table) {
            $table->uuid('bundle_id');
            $table->uuid('module_id');
            $table->timestamps();

            $table->primary(['bundle_id', 'module_id']);
            $table->foreign('bundle_id')->references('id')->on('bundles')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        // Immutable snapshot taken every time a bundle is published, so
        // contracts referencing a version keep the composition they agreed to
        // even if the bundle's live composition changes afterward.
        Schema::create('bundle_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bundle_id');
            $table->unsignedInteger('version_number');
            $table->enum('status', ['PUBLISHED', 'ARCHIVED'])->default('PUBLISHED');
            $table->timestamp('published_at')->useCurrent();
            $table->uuid('published_by')->nullable();
            $table->timestamps();

            $table->foreign('bundle_id')->references('id')->on('bundles')->cascadeOnDelete();
            $table->foreign('published_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['bundle_id', 'version_number']);
        });

        Schema::create('bundle_version_modules', function (Blueprint $table) {
            $table->uuid('bundle_version_id');
            $table->uuid('module_id');
            $table->string('module_code');

            $table->primary(['bundle_version_id', 'module_id']);
            $table->foreign('bundle_version_id')->references('id')->on('bundle_versions')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_version_modules');
        Schema::dropIfExists('bundle_versions');
        Schema::dropIfExists('bundle_modules');
        Schema::dropIfExists('bundles');
    }
};
