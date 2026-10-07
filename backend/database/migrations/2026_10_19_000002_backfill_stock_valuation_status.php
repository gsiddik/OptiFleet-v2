<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic initial classification of existing stock — it fills ONLY the new status columns; no cost, quantity or
 * movement is changed, and no evidence is invented. Uncertainty is kept (conservative):
 *  - an inbound movement is VERIFIED only when it is a receipt of a posted Goods Receipt whose line (same product, same
 *    unit cost) exists with a cost > 0 — its source is then traceable to the PO unit price; every other inbound movement
 *    (zero cost, opening balance, transfer receipt, anything untraceable) is UNVERIFIED;
 *  - a balance with stock and a unit cost of 0 is UNVERIFIED whatever its movements say (a zero proves nothing);
 *    otherwise all-verified → VERIFIED, none verified → UNVERIFIED, both kinds → MIXED (the consumed units cannot be
 *    told apart), and a balance without any inbound movement is UNVERIFIED. Balances without stock stay NULL.
 * Used tire stock has no valuation concept at all and is NOT_VALUED by definition (handled where it is read).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE stock_movements m SET
              valuation_status = CASE WHEN m.movement_type = 'RECEIPT' AND COALESCE(m.unit_cost, 0) > 0 AND EXISTS (
                    SELECT 1 FROM goods_receipt_items gi WHERE gi.goods_receipt_id = m.reference_id AND gi.product_id = m.product_id AND gi.unit_cost = m.unit_cost
                  ) THEN 'VERIFIED' ELSE 'UNVERIFIED' END,
              valuation_basis = CASE WHEN m.movement_type = 'RECEIPT' AND COALESCE(m.unit_cost, 0) > 0 AND EXISTS (
                    SELECT 1 FROM goods_receipt_items gi WHERE gi.goods_receipt_id = m.reference_id AND gi.product_id = m.product_id AND gi.unit_cost = m.unit_cost
                  ) THEN 'PO_UNIT_PRICE' ELSE 'LEGACY_UNTRACED' END,
              purchase_unit_price = CASE WHEN m.movement_type = 'RECEIPT' AND EXISTS (
                    SELECT 1 FROM goods_receipt_items gi WHERE gi.goods_receipt_id = m.reference_id AND gi.product_id = m.product_id AND gi.unit_cost = m.unit_cost
                  ) THEN m.unit_cost ELSE NULL END
            WHERE m.movement_type IN ('RECEIPT', 'OPENING', 'TRANSFER_IN') AND m.valuation_status IS NULL");

        DB::statement("UPDATE warehouse_stocks ws SET valuation_status = CASE
              WHEN ws.average_unit_cost <= 0 THEN 'UNVERIFIED'
              WHEN NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.warehouse_id = ws.warehouse_id AND m.product_id = ws.product_id AND m.valuation_status IS NOT NULL) THEN 'UNVERIFIED'
              WHEN NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.warehouse_id = ws.warehouse_id AND m.product_id = ws.product_id AND m.valuation_status = 'UNVERIFIED') THEN 'VERIFIED'
              WHEN NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.warehouse_id = ws.warehouse_id AND m.product_id = ws.product_id AND m.valuation_status = 'VERIFIED') THEN 'UNVERIFIED'
              ELSE 'MIXED' END
            WHERE ws.quantity_on_hand > 0 AND ws.valuation_status IS NULL");

        DB::statement("UPDATE warehouse_stocks SET valuation_basis = CASE
              WHEN average_unit_cost <= 0 THEN 'LEGACY_ZERO_COST'
              WHEN valuation_status = 'VERIFIED' THEN 'PO_UNIT_PRICE'
              WHEN valuation_status = 'MIXED' THEN 'MIXED_SOURCES'
              ELSE 'LEGACY_UNTRACED' END
            WHERE quantity_on_hand > 0 AND valuation_basis IS NULL AND valuation_status IS NOT NULL");
    }

    public function down(): void
    {
        // Status columns are dropped by the schema migration; nothing to restore here.
    }
};
