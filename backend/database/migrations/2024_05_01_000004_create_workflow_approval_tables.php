<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 22/26: an approval request is created once per transition attempt
 * that has an approval_rule, with one immutable step row per resolved
 * approval step — decided_by/decided_at/note are set exactly once per step
 * (never rewritten), so this pair of tables IS the Approval History the
 * spec asks for, not a separate audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('resource_type');
            $table->uuid('resource_id');
            $table->uuid('workflow_configuration_version_id');
            $table->string('transition_action_code');
            $table->string('from_status');
            $table->string('to_status');
            $table->uuid('requested_by')->nullable();
            $table->string('status')->default('PENDING'); // PENDING|APPROVED|REJECTED
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_configuration_version_id')->references('id')->on('configuration_versions')->restrictOnDelete();
            $table->index(['tenant_id', 'resource_type', 'resource_id']);
        });

        Schema::create('workflow_approval_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('approval_request_id');
            $table->unsignedInteger('step_number');
            $table->string('approver_type'); // PERMISSION|ROLE|EXPLICIT_USER
            $table->string('approver_identifier');
            $table->string('status')->default('PENDING'); // PENDING|APPROVED|REJECTED|SKIPPED
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('approval_request_id')->references('id')->on('workflow_approval_requests')->cascadeOnDelete();
            $table->unique(['approval_request_id', 'step_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_approval_steps');
        Schema::dropIfExists('workflow_approval_requests');
    }
};
