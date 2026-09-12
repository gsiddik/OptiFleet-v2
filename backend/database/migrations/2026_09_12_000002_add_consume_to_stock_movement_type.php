<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G-22: consume() previously only incremented a counter on
 * work_order_planned_parts with no immutable ledger entry — the only
 * record of consumption was a mutable column. Adds CONSUME as a valid
 * movement_type so a consumption event can be logged for traceability.
 * CONSUME carries zero on-hand balance effect (the on-hand deduction
 * already happened at ISSUE time) — it is a traceability marker, not a
 * second deduction, so ledger-reconstructs-balance logic that only
 * recognizes signed-quantity types for OPENING/RECEIPT/ISSUE/etc. is
 * unaffected by its presence.
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
            "'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP','CONSUME'".
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
            "'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP'".
            ']::character varying[]))'
        );
    }
};
