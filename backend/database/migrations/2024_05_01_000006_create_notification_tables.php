<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 28-36: Domain Event -> NotificationRule (condition + recipient
 * rules + channels + optional escalation) -> rendered NotificationTemplate
 * -> DeliveryLog. Rules are naturally a LIST per (tenant, event) — unlike
 * numbering/template/workflow there is no single "the effective config",
 * so NotificationRule is a plain versionless table rather than routed
 * through ConfigurationSet/Version (only the message templates themselves
 * reuse that versioned machinery, via type=NOTIFICATION_TEMPLATE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // null = platform-authored rule (e.g. SaaS commercial events)
            $table->string('event_code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // platform-locked: tenant cannot edit/deactivate
            $table->jsonb('condition_set')->nullable();
            $table->jsonb('recipient_rules'); // [{type, identifier}]
            $table->jsonb('channels'); // ["IN_APP","EMAIL"]
            $table->jsonb('escalation')->nullable(); // {after_minutes, recipient_rules, unresolved_condition_set}
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'event_code', 'is_active']);
        });

        Schema::create('notification_delivery_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('notification_rule_id')->nullable();
            $table->string('event_code');
            $table->string('resource_type')->nullable();
            $table->uuid('resource_id')->nullable();
            $table->uuid('recipient_user_id')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('channel');
            $table->uuid('template_configuration_version_id')->nullable();
            $table->string('status')->default('QUEUED'); // QUEUED|SENT|DELIVERED|FAILED
            $table->text('failure_reason')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('notification_rule_id')->references('id')->on('notification_rules')->nullOnDelete();
            $table->foreign('template_configuration_version_id')->references('id')->on('configuration_versions')->nullOnDelete();
            $table->index(['tenant_id', 'event_code']);
            $table->index(['status', 'escalated_at']);
        });

        Schema::create('notification_in_app_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('recipient_user_id');
            $table->string('event_code');
            $table->string('subject');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'recipient_user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_in_app_messages');
        Schema::dropIfExists('notification_delivery_logs');
        Schema::dropIfExists('notification_rules');
    }
};
