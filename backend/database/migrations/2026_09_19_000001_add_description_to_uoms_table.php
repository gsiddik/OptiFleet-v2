<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-41 (final reconciliation): the VMS Unit page lists code/name/description
 * as its field set. OptiFleet's standalone Unit page and update/delete
 * endpoints were added in Phase G; the description field itself was not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
