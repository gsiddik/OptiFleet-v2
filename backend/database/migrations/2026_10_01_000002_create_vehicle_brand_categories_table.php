<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vehicle Brand "Brand Of": a many-to-many relation to the Vehicle Category master
 * instead of the hardcoded CAR/TRUCK/BUS/HEAVY_EQUIPMENT list. The legacy usage_type /
 * usage_types columns are left untouched (read-only history). Backfill maps each legacy
 * value to the baseline platform category with the same meaning; nothing else is inferred.
 */
return new class extends Migration
{
    private const LEGACY_TO_CATEGORY = [
        'CAR' => 'VC-PCAR',
        'TRUCK' => 'VC-TRUCK',
        'BUS' => 'VC-BUS',
        'HEAVY_EQUIPMENT' => 'VC-HEQ',
    ];

    public function up(): void
    {
        Schema::create('vehicle_brand_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_brand_id');
            $table->uuid('vehicle_category_id');
            $table->timestamps();

            $table->foreign('vehicle_brand_id')->references('id')->on('vehicle_brands')->cascadeOnDelete();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->noActionOnDelete();
            $table->unique(['vehicle_brand_id', 'vehicle_category_id']);
            $table->index('vehicle_category_id');
        });

        $this->backfill();
    }

    /** Idempotent (insertOrIgnore on the unique pair). */
    public function backfill(): void
    {
        $categories = DB::table('vehicle_categories')->whereNull('tenant_id')->whereIn('code', array_values(self::LEGACY_TO_CATEGORY))->pluck('id', 'code');

        foreach (DB::table('vehicle_brands')->get(['id', 'usage_type', 'usage_types']) as $brand) {
            $legacy = json_decode((string) $brand->usage_types, true);
            $legacy = is_array($legacy) && $legacy !== [] ? $legacy : array_filter([$brand->usage_type]);

            foreach (array_unique($legacy) as $value) {
                $categoryId = $categories[self::LEGACY_TO_CATEGORY[$value] ?? ''] ?? null;
                if ($categoryId === null) {
                    continue;
                }
                DB::table('vehicle_brand_categories')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'vehicle_brand_id' => $brand->id, 'vehicle_category_id' => $categoryId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_brand_categories');
    }
};
