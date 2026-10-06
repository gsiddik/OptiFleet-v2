<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * i18n structural preparation (error / reason code decoupling), additive only: the decision engine's
 * reasons and follow-ups are also stored machine-readable — a list of {code, params} where code is the
 * EN-ID dataset key — next to the existing English text columns, so they can be rendered in another
 * language later. Rows created before this change keep null here and continue to show their stored text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tire_used_inspections', function (Blueprint $table) {
            if (! Schema::hasColumn('tire_used_inspections', 'reason_codes')) {
                $table->jsonb('reason_codes')->nullable()->after('reasons');
            }
            if (! Schema::hasColumn('tire_used_inspections', 'follow_up_codes')) {
                $table->jsonb('follow_up_codes')->nullable()->after('follow_ups');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tire_used_inspections', function (Blueprint $table) {
            $table->dropColumn(['reason_codes', 'follow_up_codes']);
        });
    }
};
