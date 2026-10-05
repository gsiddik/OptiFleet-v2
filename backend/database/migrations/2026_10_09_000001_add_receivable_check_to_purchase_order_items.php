<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remaining Receivable Qty (Ordered − Received − Refunded, see PurchaseOrderQuantityService) can
 * never go below zero: Goods Receipt and Refund Requests are both capped by it in the services,
 * and this CHECK is the database backstop. Rows that already violate it (data written by the old
 * calculation) are left untouched — the constraint is then added NOT VALID, so it guards every new
 * write without rewriting history; validate it once those rows are reviewed.
 */
return new class extends Migration
{
    private const CHECK = 'quantity_received + quantity_refunded <= quantity_ordered';

    public function up(): void
    {
        $violations = DB::table('purchase_order_items')->whereRaw('NOT ('.self::CHECK.')')->count();
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_receivable_check CHECK ('.self::CHECK.')'.($violations > 0 ? ' NOT VALID' : ''));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT IF EXISTS purchase_order_items_receivable_check');
    }
};
