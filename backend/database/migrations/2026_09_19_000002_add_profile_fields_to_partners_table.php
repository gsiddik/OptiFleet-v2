<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-42 (final reconciliation): the VMS Supplier/Workshop Partner forms list
 * Province/City/Account Holder/Account Number/Bank/Description alongside
 * the contact/tax fields Partner already has. Deliberately excludes the
 * VMS "Supplier Type" checklist (Oil/Spareparts/Tires and Wheels/
 * Attachment/Optional Accessories) — that conflicts with the existing
 * single-select partner_type enum used for eligibility gating elsewhere
 * (e.g. TireService::ELIGIBLE_SERVICE_PARTNER_TYPES) and needs a taxonomy
 * decision, not a field addition (see IMPROVEMENT_CONTEXT.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('province')->nullable()->after('address');
            $table->string('city')->nullable()->after('province');
            $table->string('bank')->nullable()->after('payment_terms');
            $table->string('account_holder')->nullable()->after('bank');
            $table->string('account_number')->nullable()->after('account_holder');
            $table->text('description')->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['province', 'city', 'bank', 'account_holder', 'account_number', 'description']);
        });
    }
};
