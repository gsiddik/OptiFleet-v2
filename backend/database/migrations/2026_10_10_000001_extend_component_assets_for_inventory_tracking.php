<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Component Assets become the physical-asset register under Inventory (additive only):
 *
 *  - statuses SOLD (Sell Sparepart approved) and RETURNED_TO_VENDOR (Return to Vendor, owner
 *    decision: the user selects the Asset# returned); existing statuses are unchanged;
 *  - goods_receipt_item_id + receipt_sequence: the Goods Receipt line (and unit) that generated
 *    the asset — unique together, so a receipt line can never generate the same unit twice;
 *  - purchase_return_id: the Return Order that sent the asset back to the vendor;
 *  - sold_at / spare_part_sale_id: the sale that sold it (history is kept, never deleted);
 *  - Asset# unique per tenant (component_assets_asset_number_unique);
 *  - location invariants (INSTALLED/ACTIVE → vehicle; SOLD / RETURNED_TO_VENDOR → no location;
 *    IN_STOCK → warehouse; never both). Added NOT VALID when legacy rows violate them, so
 *    existing data keeps working while every new write is checked.
 *
 * Sell Sparepart gets the COMPONENT_ASSET source (one asset per sale line).
 */
return new class extends Migration
{
    private const STATUSES = ['IN_STOCK', 'INSTALLED', 'ACTIVE', 'FAILED', 'REMOVED', 'UNDER_REPAIR', 'RECONDITIONED', 'SCRAPPED', 'SOLD', 'RETURNED_TO_VENDOR'];

    private const LEGACY_STATUSES = ['IN_STOCK', 'INSTALLED', 'ACTIVE', 'FAILED', 'REMOVED', 'UNDER_REPAIR', 'RECONDITIONED', 'SCRAPPED'];

    public function up(): void
    {
        Schema::table('component_assets', function (Blueprint $table) {
            $table->uuid('goods_receipt_item_id')->nullable();
            $table->unsignedInteger('receipt_sequence')->nullable();
            $table->uuid('purchase_return_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->uuid('spare_part_sale_id')->nullable();
            $table->foreign('goods_receipt_item_id')->references('id')->on('goods_receipt_items')->restrictOnDelete();
            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->nullOnDelete();
            $table->index('goods_receipt_item_id');
        });
        $this->statusCheck(self::STATUSES);

        DB::statement('CREATE UNIQUE INDEX component_assets_receipt_unit_unique ON component_assets (goods_receipt_item_id, receipt_sequence) WHERE goods_receipt_item_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX component_assets_asset_number_unique ON component_assets (tenant_id, asset_number) WHERE asset_number IS NOT NULL AND deleted_at IS NULL');

        $invariant = "(current_status NOT IN ('INSTALLED', 'ACTIVE') OR current_vehicle_id IS NOT NULL)
            AND (current_status NOT IN ('SOLD', 'RETURNED_TO_VENDOR') OR (current_vehicle_id IS NULL AND current_warehouse_id IS NULL))
            AND (current_status <> 'IN_STOCK' OR current_warehouse_id IS NOT NULL)
            AND (current_vehicle_id IS NULL OR current_warehouse_id IS NULL)";
        $violations = DB::table('component_assets')->whereRaw("NOT ({$invariant})")->count();
        DB::statement("ALTER TABLE component_assets ADD CONSTRAINT component_assets_location_check CHECK ({$invariant})".($violations > 0 ? ' NOT VALID' : ''));

        Schema::table('spare_part_sales', function (Blueprint $table) {
            $table->uuid('component_asset_id')->nullable();
            $table->string('asset_number')->nullable();
            $table->foreign('component_asset_id')->references('id')->on('component_assets')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE spare_part_sales DROP CONSTRAINT spare_part_sales_source_check');
        DB::statement("ALTER TABLE spare_part_sales ADD CONSTRAINT spare_part_sales_source_check CHECK (
            (source_type = 'USED_SPAREPART' AND work_order_part_return_id IS NOT NULL AND warehouse_id IS NOT NULL AND tire_id IS NULL AND component_asset_id IS NULL)
            OR (source_type = 'SCRAPPED_TIRE' AND tire_id IS NOT NULL AND tire_serial_number IS NOT NULL AND work_order_part_return_id IS NULL AND component_asset_id IS NULL AND quantity = 1)
            OR (source_type = 'COMPONENT_ASSET' AND component_asset_id IS NOT NULL AND work_order_part_return_id IS NULL AND tire_id IS NULL AND quantity = 1))");
        DB::statement("CREATE UNIQUE INDEX spare_part_sales_one_active_per_asset ON spare_part_sales (component_asset_id) WHERE component_asset_id IS NOT NULL AND status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED')");
        DB::statement('ALTER TABLE component_assets ADD CONSTRAINT component_assets_spare_part_sale_fk FOREIGN KEY (spare_part_sale_id) REFERENCES spare_part_sales (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE component_assets DROP CONSTRAINT IF EXISTS component_assets_spare_part_sale_fk');
        DB::statement('DROP INDEX IF EXISTS spare_part_sales_one_active_per_asset');
        DB::statement('ALTER TABLE spare_part_sales DROP CONSTRAINT IF EXISTS spare_part_sales_source_check');
        DB::statement("ALTER TABLE spare_part_sales ADD CONSTRAINT spare_part_sales_source_check CHECK (
            (source_type = 'USED_SPAREPART' AND work_order_part_return_id IS NOT NULL AND warehouse_id IS NOT NULL AND tire_id IS NULL)
            OR (source_type = 'SCRAPPED_TIRE' AND tire_id IS NOT NULL AND tire_serial_number IS NOT NULL AND work_order_part_return_id IS NULL AND quantity = 1)) NOT VALID");
        Schema::table('spare_part_sales', function (Blueprint $table) {
            $table->dropForeign(['component_asset_id']);
            $table->dropColumn(['component_asset_id', 'asset_number']);
        });

        DB::statement('ALTER TABLE component_assets DROP CONSTRAINT IF EXISTS component_assets_location_check');
        DB::statement('DROP INDEX IF EXISTS component_assets_asset_number_unique');
        DB::statement('DROP INDEX IF EXISTS component_assets_receipt_unit_unique');
        $this->statusCheck(self::LEGACY_STATUSES, true);
        Schema::table('component_assets', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_item_id']);
            $table->dropForeign(['purchase_return_id']);
            $table->dropIndex(['goods_receipt_item_id']);
            $table->dropColumn(['goods_receipt_item_id', 'receipt_sequence', 'purchase_return_id', 'sold_at', 'spare_part_sale_id']);
        });
    }

    private function statusCheck(array $statuses, bool $notValid = false): void
    {
        DB::statement('ALTER TABLE component_assets DROP CONSTRAINT IF EXISTS component_assets_current_status_check');
        $list = implode(', ', array_map(fn ($s) => "'{$s}'", $statuses));
        DB::statement("ALTER TABLE component_assets ADD CONSTRAINT component_assets_current_status_check CHECK (current_status IN ({$list}))".($notValid ? ' NOT VALID' : ''));
    }
};
