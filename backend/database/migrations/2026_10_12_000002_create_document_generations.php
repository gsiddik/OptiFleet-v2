<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * i18n structural preparation — PRINT_LOCALE_SNAPSHOT (owner decisions D1, D3, D4). Additive only.
 *
 * - document_generations: one immutable row per generated printed document, pinning the locale and the
 *   published template version used, so a reprint renders exactly the historical generation even after
 *   the template or anyone's language preference changes. "Generate New Version" adds a row; rows are
 *   never updated or deleted. PDFs are rendered on demand and not stored, so there is no file column.
 * - tenants.default_locale / users.preferred_locale: the two preference levels of the document language
 *   priority (explicit print choice → user → tenant → system fallback en). Null means "not set".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'default_locale')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('default_locale', 5)->nullable()->after('timezone');
            });
        }

        if (! Schema::hasColumn('users', 'preferred_locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('preferred_locale', 5)->nullable()->after('status');
            });
        }

        if (Schema::hasTable('document_generations')) {
            return;
        }

        Schema::create('document_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_type', 64);
            $table->string('source_entity_type', 64);
            $table->uuid('source_entity_id');
            // Only for documents generated per recipient of one source (an RFQ is printed per invited vendor).
            $table->uuid('recipient_partner_id')->nullable();
            $table->string('locale', 5);
            // Null only when no published template exists and the platform's built-in body was used.
            $table->uuid('template_id')->nullable();
            $table->uuid('template_version_id')->nullable();
            $table->unsignedInteger('template_version')->nullable();
            // Microsecond precision: generations of one document are ordered by it.
            $table->timestamp('generated_at', 6);
            $table->uuid('generated_by')->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('template_id')->references('id')->on('configuration_sets')->restrictOnDelete();
            $table->foreign('template_version_id')->references('id')->on('configuration_versions')->restrictOnDelete();
            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'source_entity_type', 'source_entity_id', 'generated_at'], 'document_generations_source_index');
        });

        // History is append-only: an UPDATE is refused by the database too, not only by the model. The one
        // exception is the generated_by foreign key's own ON DELETE SET NULL when a user is removed.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION document_generations_immutable() RETURNS trigger AS $$
                BEGIN
                    IF NEW.generated_by IS NULL AND OLD.generated_by IS NOT NULL
                        AND (to_jsonb(NEW) - 'generated_by') = (to_jsonb(OLD) - 'generated_by') THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'document_generations rows are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER document_generations_no_update BEFORE UPDATE ON document_generations
                    FOR EACH ROW EXECUTE FUNCTION document_generations_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_generations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS document_generations_immutable()');
        }
        if (Schema::hasColumn('users', 'preferred_locale')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferred_locale'));
        }
        if (Schema::hasColumn('tenants', 'default_locale')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('default_locale'));
        }
    }
};
