<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sell Sparepart also sells scrapped tires (Used Tire Management → Scrap → Recently Scrapped),
 * one physical tire per sale line, keeping the tire's identity:
 *
 *  source_type   USED_SPAREPART (existing: a SELL_ELIGIBLE used-part return, from a warehouse)
 *                SCRAPPED_TIRE  (a tire in SCRAPPED status; quantity 1; no warehouse quantity)
 *  tire_id, tire_serial_number, tire_status, tire_condition   identity snapshot at the sale
 *
 * A tire can be in at most one active (draft / pending / approved) sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spare_part_sales', function (Blueprint $table) {
            $table->string('source_type', 20)->default('USED_SPAREPART');
            $table->uuid('tire_id')->nullable();
            $table->string('tire_serial_number')->nullable();
            $table->string('tire_status', 20)->nullable();
            $table->string('tire_condition')->nullable();
            $table->foreign('tire_id')->references('id')->on('tires')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE spare_part_sales ALTER COLUMN work_order_part_return_id DROP NOT NULL');
        DB::statement('ALTER TABLE spare_part_sales ALTER COLUMN warehouse_id DROP NOT NULL');
        DB::statement("ALTER TABLE spare_part_sales ADD CONSTRAINT spare_part_sales_source_check CHECK (
            (source_type = 'USED_SPAREPART' AND work_order_part_return_id IS NOT NULL AND warehouse_id IS NOT NULL AND tire_id IS NULL)
            OR (source_type = 'SCRAPPED_TIRE' AND tire_id IS NOT NULL AND tire_serial_number IS NOT NULL AND work_order_part_return_id IS NULL AND quantity = 1))");
        DB::statement("CREATE UNIQUE INDEX spare_part_sales_one_active_per_tire ON spare_part_sales (tire_id) WHERE tire_id IS NOT NULL AND status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS spare_part_sales_one_active_per_tire');
        DB::statement('ALTER TABLE spare_part_sales DROP CONSTRAINT IF EXISTS spare_part_sales_source_check');
        Schema::table('spare_part_sales', function (Blueprint $table) {
            $table->dropForeign(['tire_id']);
            $table->dropColumn(['source_type', 'tire_id', 'tire_serial_number', 'tire_status', 'tire_condition']);
        });
    }
};
