<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-08 (final reconciliation, second clause): "no line-level hold/reject-
 * reason" — a Purchase Request could previously only be approved/rejected
 * as a whole. This adds a per-line status + reason so an individual item
 * can be held or rejected while the rest of the request proceeds, without
 * inventing any approval threshold or tier policy (that remains G-06,
 * explicitly deferred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->enum('line_status', ['PENDING', 'APPROVED', 'ON_HOLD', 'REJECTED'])->default('PENDING')->after('notes');
            $table->text('line_reason')->nullable()->after('line_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn(['line_status', 'line_reason']);
        });
    }
};
