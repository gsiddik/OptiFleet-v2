<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation: the VMS Company form lists Logo alongside the
 * profile fields Tenant already has (name/address/province/city/phone/
 * fax/email/website). Follows the same shallow nullable-string-URL
 * pattern as `evidence`/`photo_url`/`image_url` elsewhere. FMS flag and
 * E-Kiosk flag are deliberately NOT added — a boolean with no defined
 * behavior would be a fabricated feature toggle, not a data field (see
 * IMPROVEMENT_CONTEXT.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('logo_url');
        });
    }
};
