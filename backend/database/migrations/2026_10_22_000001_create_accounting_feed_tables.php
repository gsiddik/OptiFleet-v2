<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA6 accounting feed (read-only pull API for OptiEntry): additive tables only,
 * the existing integration outbox is not altered.
 *
 *  - accounting_feed_credentials: hashed, read-only machine credentials, each scoped to ONE tenant.
 *  - accounting_feed_counters:    one row per tenant; the row lock taken while allocating the next
 *                                 sequence is held until the surrounding transaction commits, so the
 *                                 sequence is gap-free and in commit order (a reader that sees N never
 *                                 misses an N-1 that is still in flight).
 *  - accounting_feed_events:      append-only canonical events, one per outbox row, each with its
 *                                 per-tenant monotonic `sequence` and the event-time snapshot payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_feed_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('token_prefix', 16);
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('accounting_feed_counters', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('accounting_feed_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('sequence');
            $table->uuid('event_id')->unique();
            $table->string('event_type');
            $table->timestampTz('occurred_at');
            $table->string('correlation_id');
            $table->json('source');
            $table->json('dimensions');
            $table->json('payload');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'sequence']);
        });

        $this->check('accounting_feed_events', 'sequence_positive', 'sequence > 0');
        $this->check('accounting_feed_counters', 'last_sequence_non_negative', 'last_sequence >= 0');

        // Published feed events are immutable: a cursor that has passed an event must never see it change.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounting_feed_events_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'accounting_feed_events rows are append-only';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER accounting_feed_events_no_update BEFORE UPDATE ON accounting_feed_events FOR EACH ROW EXECUTE FUNCTION accounting_feed_events_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS accounting_feed_events_no_update ON accounting_feed_events');
        DB::statement('DROP FUNCTION IF EXISTS accounting_feed_events_immutable()');
        Schema::dropIfExists('accounting_feed_events');
        Schema::dropIfExists('accounting_feed_counters');
        Schema::dropIfExists('accounting_feed_credentials');
    }

    private function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$expression})");
    }
};
