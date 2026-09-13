<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-39 completion (final reconciliation): Phase G's company-profile batch
 * added address/phone/email/website but not Province/City/Fax, which the
 * VMS Company form also lists. Mirrors the same fields now being added to
 * Partner for consistency between the two comparable business-entity
 * records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('province')->nullable()->after('address');
            $table->string('city')->nullable()->after('province');
            $table->string('fax')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['province', 'city', 'fax']);
        });
    }
};
