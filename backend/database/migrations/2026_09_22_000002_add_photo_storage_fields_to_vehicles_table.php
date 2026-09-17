<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 7: the New/Edit Vehicle photo picker uploads through the private
 * storage disk (same pattern as vehicle documents) rather than accepting a
 * raw URL. `photo_url` is kept, untouched, for vehicles created before this
 * change and any external integration that still sets it directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('photo_disk')->nullable()->after('photo_url');
            $table->string('photo_path')->nullable()->after('photo_disk');
            $table->string('photo_mime_type')->nullable()->after('photo_path');
            $table->unsignedBigInteger('photo_size')->nullable()->after('photo_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['photo_disk', 'photo_path', 'photo_mime_type', 'photo_size']);
        });
    }
};
