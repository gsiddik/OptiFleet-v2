<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F / BD-3: the reference (new/original) tread depth a tire's
 * remaining tread is measured against — "KTN" — is sourced from the Tire
 * Product's own spec, never guessed or hardcoded per-tire. Nullable and
 * never back-filled: an existing Tire product with no recorded reference
 * depth simply cannot be scored yet (TireScoringService rejects the
 * calculation) rather than having a fabricated value invented for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reference_tread_depth_mm', 6, 2)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reference_tread_depth_mm');
        });
    }
};
