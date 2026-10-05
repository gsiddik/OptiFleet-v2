<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle Documents: Vehicle Tax document type, and per document "Have an Expiry Date?" (expiry
 * date mandatory when checked) and "Need to be extended?" (extension deadline mandatory when
 * checked). Existing documents with an expiry date are backfilled as has_expiry = true.
 */
return new class extends Migration
{
    private const TYPES = ['REGISTRATION', 'INSPECTION_CERTIFICATE', 'INSURANCE', 'PERMIT', 'WARRANTY', 'OTHER'];

    public function up(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->boolean('has_expiry')->default(false);
            $table->boolean('needs_extension')->default(false);
            $table->date('extension_deadline')->nullable();
        });
        DB::table('vehicle_documents')->whereNotNull('expiry_date')->update(['has_expiry' => true]);

        DB::statement('ALTER TABLE vehicle_documents DROP CONSTRAINT IF EXISTS vehicle_documents_document_type_check');
        DB::statement("ALTER TABLE vehicle_documents ADD CONSTRAINT vehicle_documents_document_type_check CHECK (document_type::text IN ('".implode("','", [...self::TYPES, 'VEHICLE_TAX'])."'))");
        DB::statement('ALTER TABLE vehicle_documents ADD CONSTRAINT vehicle_documents_expiry_check CHECK (has_expiry = (expiry_date IS NOT NULL))');
        DB::statement('ALTER TABLE vehicle_documents ADD CONSTRAINT vehicle_documents_extension_check CHECK (needs_extension = (extension_deadline IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vehicle_documents DROP CONSTRAINT IF EXISTS vehicle_documents_extension_check');
        DB::statement('ALTER TABLE vehicle_documents DROP CONSTRAINT IF EXISTS vehicle_documents_expiry_check');
        DB::statement('ALTER TABLE vehicle_documents DROP CONSTRAINT IF EXISTS vehicle_documents_document_type_check');
        DB::statement("ALTER TABLE vehicle_documents ADD CONSTRAINT vehicle_documents_document_type_check CHECK (document_type::text IN ('".implode("','", self::TYPES)."'))");
        Schema::table('vehicle_documents', fn (Blueprint $t) => $t->dropColumn(['has_expiry', 'needs_extension', 'extension_deadline']));
    }
};
