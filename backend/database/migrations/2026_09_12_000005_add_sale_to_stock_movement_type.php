<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G-16: SALE is a traceability marker like CONSUME — zero on-hand effect.
 * A SparePartSale can only reference a work_order_part_returns row whose
 * disposition is SELL_ELIGIBLE, and that quantity was never added to
 * quantity_on_hand (Phase A/B design) — so selling it does not decrement
 * a balance it was never part of. This gives the sale an immutable
 * ledger entry for audit/history without touching stock reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check '.
            'CHECK (movement_type::text = ANY (ARRAY['.
            "'OPENING','RECEIPT','RESERVATION','RELEASE_RESERVATION','ISSUE','RETURN',".
            "'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP','CONSUME','SALE'".
            ']::character varying[]))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check '.
            'CHECK (movement_type::text = ANY (ARRAY['.
            "'OPENING','RECEIPT','RESERVATION','RELEASE_RESERVATION','ISSUE','RETURN',".
            "'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP','CONSUME'".
            ']::character varying[]))'
        );
    }
};
