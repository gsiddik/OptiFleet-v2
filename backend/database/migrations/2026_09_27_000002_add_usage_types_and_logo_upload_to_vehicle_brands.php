<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" (Vehicle Brands):
 * "Dropdown Brand Of dapat dipilih lebih dari 1" (multi-select) and
 * "ubah pula LOGO URL menjadi Upload Logo yang menerima JPG atau PNG"
 * (replace the free-text Logo URL with an actual file upload).
 *
 * `usage_types` is additive (JSON array) alongside the existing single-
 * value `usage_type`, which is left untouched for backward compat — no
 * code reads it besides the deprecated create/update path this migration's
 * follow-up commit stops writing to. The logo upload columns follow the
 * exact same secure-file pattern as vehicles.photo_* (private disk, UUID
 * filename, served only via an authenticated controller action); the
 * existing `logo_url` column is also left in place for any legacy value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_brands', function (Blueprint $table) {
            $table->json('usage_types')->nullable()->after('usage_type');
            $table->string('logo_disk')->nullable()->after('logo_url');
            $table->string('logo_path')->nullable()->after('logo_disk');
            $table->string('logo_mime_type')->nullable()->after('logo_path');
            $table->unsignedInteger('logo_size')->nullable()->after('logo_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_brands', function (Blueprint $table) {
            $table->dropColumn(['usage_types', 'logo_disk', 'logo_path', 'logo_mime_type', 'logo_size']);
        });
    }
};
