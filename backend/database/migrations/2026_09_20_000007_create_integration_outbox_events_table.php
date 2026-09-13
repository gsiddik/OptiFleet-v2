<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R1 §10 (future accounting integration boundary): a real, tenant-scoped,
 * idempotent, retry-safe outbox table for events a future accounting
 * connector would consume (workshop_invoice.recorded/corrected/cancelled,
 * workshop_invoice.payment_recorded, maintenance_memo.billed/paid). No
 * connector exists yet — IntegrationOutboxService only ever writes PENDING
 * rows, transactionally alongside the domain state change, via a
 * `firstOrCreate` keyed on the same (tenant_id, event_type, aggregate_id)
 * unique index this migration creates, so calling it twice for the same
 * underlying occurrence (each occurrence is itself a row created exactly
 * once — an invoice, a payment, a decided correction/cancellation) can
 * never create a duplicate event. Delivery (PENDING -> DELIVERED/FAILED)
 * is left for a future connector to implement against an approved
 * integration contract — this migration only prepares the boundary, per
 * the explicit instruction not to fabricate a successful posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('correlation')->nullable();
            $table->json('payload');
            $table->enum('status', ['PENDING', 'DELIVERED', 'FAILED'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'event_type', 'aggregate_id'], 'integration_outbox_events_idempotency_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox_events');
    }
};
