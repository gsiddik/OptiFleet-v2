<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation (queued ADJUST): VMS's Vehicle Brand form has a
 * Logo image and a "Brand Of Car/Truck/Bus/Heavy Equipment" usage-type
 * classifier, neither present since Brand/Model master data was added in
 * Phase G. Both are plain descriptive attributes with no policy question.
 * `logo_url` follows the shallow nullable-string-URL pattern used
 * elsewhere (`evidence`/`photo_url`/`image_url`). `usage_type` is a fixed,
 * small enum validated at the application layer (FormRequest), matching
 * the convention used for `priority`/`maintenance_type` rather than a DB
 * CHECK constraint, since it gates no downstream business rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_brands', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->after('name');
            $table->string('usage_type')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_brands', function (Blueprint $table) {
            $table->dropColumn(['logo_url', 'usage_type']);
        });
    }
};
