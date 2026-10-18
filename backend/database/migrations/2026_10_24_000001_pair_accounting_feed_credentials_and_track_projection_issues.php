<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting feed hardening (audit A17, A19). Additive only; nothing existing is rewritten.
 *
 *  - accounting_feed_credentials: the OptiNexus tenant the credential was issued for (pairing), the consumer application it is
 *    meant for, who issued it, the credential it replaced (rotation) and why it was revoked. A credential issued before this
 *    migration has no pairing and is refused while pairing is enforced; rotating it issues a paired one.
 *  - accounting_feed_events.review: set when a fact is added later from the current state of its source (backfill / repair). It marks
 *    the event as quarantined for the consumer: it must be reviewed, never posted automatically. Published events stay immutable
 *    (the append-only trigger is untouched); events created before this column keep NULL and still say so in payload.snapshot_basis.
 *  - accounting_feed_projection_issues: one row per outbox fact that has no feed event because its projection failed or its source
 *    rows are gone, so a missed projection is visible and can be repaired or waived on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_feed_credentials', function (Blueprint $table) {
            $table->uuid('nexus_tenant_id')->nullable()->after('tenant_id');
            $table->string('consumer_application', 64)->nullable()->after('nexus_tenant_id');
            $table->string('issued_by')->nullable()->after('name');
            $table->uuid('rotated_from_id')->nullable()->after('revoked_at');
            $table->string('revoked_reason')->nullable()->after('rotated_from_id');

            $table->index('nexus_tenant_id');
            $table->foreign('rotated_from_id')->references('id')->on('accounting_feed_credentials')->nullOnDelete();
        });

        Schema::table('accounting_feed_events', function (Blueprint $table) {
            $table->json('review')->nullable()->after('payload');
        });

        Schema::create('accounting_feed_projection_issues', function (Blueprint $table) {
            $table->uuid('outbox_event_id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type');
            $table->string('reason', 40);
            $table->string('detail', 500)->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->string('resolved_by')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_feed_projection_issues');

        Schema::table('accounting_feed_events', function (Blueprint $table) {
            $table->dropColumn('review');
        });

        Schema::table('accounting_feed_credentials', function (Blueprint $table) {
            $table->dropForeign(['rotated_from_id']);
            $table->dropIndex(['nexus_tenant_id']);
            $table->dropColumn(['nexus_tenant_id', 'consumer_application', 'issued_by', 'rotated_from_id', 'revoked_reason']);
        });
    }
};
