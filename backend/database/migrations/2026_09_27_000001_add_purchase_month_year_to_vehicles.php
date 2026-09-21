<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" (Vehicle): "Saat Create New
 * Vehicle, tambahkan data: Dropdown Bulan (Januari-Desember), Textfield
 * Tahun Pembelian" — Purchase Month/Year, distinct from the existing
 * `year` column (manufacturing year). Additive and nullable so every
 * existing vehicle keeps working unmodified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('purchase_month')->nullable()->after('year');
            $table->unsignedSmallInteger('purchase_year')->nullable()->after('purchase_month');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['purchase_month', 'purchase_year']);
        });
    }
};
