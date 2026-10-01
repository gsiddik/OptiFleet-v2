<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor quotation document (PDF / DOC / DOCX) required when recording a quotation. Nullable:
 * quotations recorded before this change keep no attachment. The file lives on the private disk
 * and is only served through the authorized quotation endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_quotations', function (Blueprint $table) {
            $table->string('attachment_disk')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_filename')->nullable();
            $table->string('attachment_mime_type')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->uuid('attachment_uploaded_by')->nullable();
            $table->timestamp('attachment_uploaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_quotations', function (Blueprint $table) {
            $table->dropColumn([
                'attachment_disk', 'attachment_path', 'attachment_original_filename', 'attachment_mime_type',
                'attachment_size', 'attachment_uploaded_by', 'attachment_uploaded_at',
            ]);
        });
    }
};
