<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" Section (Mechanic): Worker
 * Type becomes real master data instead of a fixed 5-value DB enum, so a
 * tenant can manage its own positions. The existing `workers.worker_type`
 * enum column is kept untouched for backward compatibility (existing
 * queries/reports keyed on it keep working) — this migration only adds
 * an additive, nullable `worker_type_id` FK and seeds one system row per
 * existing enum value, then backfills every existing Worker row to point
 * at the matching row. No existing data is altered or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX worker_types_system_code_unique ON worker_types (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::table('workers', function (Blueprint $table) {
            $table->uuid('worker_type_id')->nullable()->after('worker_type');
        });
        Schema::table('workers', function (Blueprint $table) {
            $table->foreign('worker_type_id')->references('id')->on('worker_types')->nullOnDelete();
        });

        $now = now();
        $legacyTypes = [
            'LEAD_MECHANIC' => 'Lead Mechanic',
            'MECHANIC' => 'Mechanic',
            'TECHNICIAN' => 'Technician',
            'INSPECTOR' => 'Inspector',
            'QC' => 'QC',
        ];
        $ids = [];
        foreach ($legacyTypes as $code => $name) {
            $ids[$code] = (string) \Illuminate\Support\Str::uuid();
            DB::table('worker_types')->insert([
                'id' => $ids[$code], 'tenant_id' => null, 'code' => $code, 'name' => $name,
                'is_system' => true, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($ids as $code => $id) {
            DB::table('workers')->where('worker_type', $code)->update(['worker_type_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropForeign(['worker_type_id']);
            $table->dropColumn('worker_type_id');
        });
        Schema::dropIfExists('worker_types');
    }
};
