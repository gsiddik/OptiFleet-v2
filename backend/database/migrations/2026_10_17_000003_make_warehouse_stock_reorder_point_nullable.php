<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Low Stock (owner decision): "threshold not set" must be distinguishable from a deliberate zero.
 * reorder_point becomes nullable without a default; every existing 0 came from the old column default
 * (the edit form could not store an empty value) and is migrated to "not set". From now on 0 is only
 * stored when a user enters 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE warehouse_stocks ALTER COLUMN reorder_point DROP NOT NULL');
        DB::statement('ALTER TABLE warehouse_stocks ALTER COLUMN reorder_point DROP DEFAULT');
        DB::table('warehouse_stocks')->where('reorder_point', 0)->update(['reorder_point' => null]);
    }

    public function down(): void
    {
        DB::table('warehouse_stocks')->whereNull('reorder_point')->update(['reorder_point' => 0]);
        DB::statement("ALTER TABLE warehouse_stocks ALTER COLUMN reorder_point SET DEFAULT '0'");
        DB::statement('ALTER TABLE warehouse_stocks ALTER COLUMN reorder_point SET NOT NULL');
    }
};
