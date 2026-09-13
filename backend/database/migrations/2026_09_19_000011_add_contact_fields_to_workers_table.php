<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation: the VMS Worker form lists Phone, Email, Photo,
 * Address, Monthly Rate, Hourly Rate alongside Name/Job Position — Worker
 * previously had none of these. All are plain descriptive/compensation
 * data, not policy values. "Same Domicile flag" + "Domicile Address" are
 * an Indonesia-specific residency-registration concept with no OptiFleet
 * analog and no established pattern to extend safely — left out
 * (DEFERRED_DECISION, see IMPROVEMENT_CONTEXT.md), unlike the other six
 * fields which are unambiguous. `photo_url` follows the same shallow
 * nullable-string-URL pattern as `evidence`/`photo_url` elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('worker_type');
            $table->string('email')->nullable()->after('phone');
            $table->text('address')->nullable()->after('email');
            $table->decimal('monthly_rate', 14, 2)->nullable()->after('address');
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('monthly_rate');
            $table->string('photo_url')->nullable()->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email', 'address', 'monthly_rate', 'hourly_rate', 'photo_url']);
        });
    }
};
