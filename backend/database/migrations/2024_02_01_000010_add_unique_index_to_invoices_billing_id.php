<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes a TOCTOU race in InvoiceService::generateFromBilling(): the
     * pre-transaction "does an invoice already exist for this billing?"
     * check is not itself atomic, so without a DB constraint two concurrent
     * calls for the same billing could both pass it and create duplicate
     * invoices. Postgres unique indexes treat NULL as distinct, so
     * adjustment invoices (billing_id null) remain unrestricted.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('billing_id', 'invoices_billing_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_billing_id_unique');
        });
    }
};
