<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product Vehicle Compatibility: Brand / Model become references to the Vehicle Brand /
 * Vehicle Model masters instead of free text. Additive and forward-safe:
 *  - vehicle_brand_id / vehicle_model_id are nullable; the legacy vehicle_brand /
 *    vehicle_model text columns stay and keep the name snapshot (history, matching).
 *  - Backfill links a row only when its text matches exactly one master by name
 *    (case-insensitive, trimmed; the row's own tenant brand wins over a platform brand).
 *    Anything ambiguous or unknown keeps its text and stays unlinked — never guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_compatibilities', function (Blueprint $table) {
            $table->uuid('vehicle_brand_id')->nullable()->after('vehicle_brand');
            $table->uuid('vehicle_model_id')->nullable()->after('vehicle_model');
            $table->foreign('vehicle_brand_id')->references('id')->on('vehicle_brands')->noActionOnDelete();
            $table->foreign('vehicle_model_id')->references('id')->on('vehicle_models')->noActionOnDelete();
            $table->index('vehicle_brand_id');
            $table->index('vehicle_model_id');
        });

        $this->backfill();
    }

    /** Idempotent: only rows not linked yet are considered. */
    public function backfill(): void
    {
        $rows = DB::table('product_compatibilities')->whereNotNull('vehicle_brand')->whereNull('vehicle_brand_id')->get(['id', 'tenant_id', 'vehicle_brand', 'vehicle_model']);
        foreach ($rows as $row) {
            $brandId = $this->uniqueMatch(
                DB::table('vehicle_brands')->whereNull('deleted_at')
                    ->whereRaw('lower(trim(name)) = lower(trim(?))', [$row->vehicle_brand]),
                $row->tenant_id,
            );
            if ($brandId === null) {
                continue;
            }

            $modelId = null;
            if ($row->vehicle_model !== null && trim($row->vehicle_model) !== '') {
                $models = DB::table('vehicle_models')->whereNull('deleted_at')->where('vehicle_brand_id', $brandId)
                    ->whereRaw('lower(trim(name)) = lower(trim(?))', [$row->vehicle_model])->pluck('id');
                $modelId = $models->count() === 1 ? $models->first() : null;
            }

            DB::table('product_compatibilities')->where('id', $row->id)->update(['vehicle_brand_id' => $brandId, 'vehicle_model_id' => $modelId]);
        }
    }

    /** The row tenant's own brand when exactly one matches; else exactly one platform brand; else none. */
    private function uniqueMatch($query, ?string $tenantId): ?string
    {
        if ($tenantId !== null) {
            $own = (clone $query)->where('tenant_id', $tenantId)->pluck('id');
            if ($own->count() > 0) {
                return $own->count() === 1 ? $own->first() : null;
            }
        }
        $platform = (clone $query)->whereNull('tenant_id')->pluck('id');

        return $platform->count() === 1 ? $platform->first() : null;
    }

    public function down(): void
    {
        Schema::table('product_compatibilities', function (Blueprint $table) {
            $table->dropForeign(['vehicle_brand_id']);
            $table->dropForeign(['vehicle_model_id']);
            $table->dropColumn(['vehicle_brand_id', 'vehicle_model_id']);
        });
    }
};
