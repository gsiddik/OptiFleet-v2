<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-15: Tenant had only name/code/legal_name/industry/timezone — no tax
 * ID, address, or contact info at all, unlike Partner (a comparable
 * business-entity record) which already has all of these. Mirrors
 * Partner's existing field set rather than inventing a new shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tax_id')->nullable()->after('industry');
            $table->text('address')->nullable()->after('tax_id');
            $table->string('phone')->nullable()->after('address');
            $table->string('email')->nullable()->after('phone');
            $table->string('website')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['tax_id', 'address', 'phone', 'email', 'website']);
        });
    }
};
