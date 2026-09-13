<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R1: links a Maintenance Memo to the Workshop Invoice that moved it to BILLED (nullable — most memos never reach BILLED). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->uuid('workshop_invoice_id')->nullable()->after('requested_parts_services');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->dropColumn('workshop_invoice_id');
        });
    }
};
