<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * work_order_part_returns carries three separate, explicit lifecycles (return_source):
 *
 *  NEW_PART           an issued new part that was not used, returned from Issuance & Return:
 *                     PENDING_PROCESSING -> (Returned Parts Processing) RESTOCKED | QUARANTINED.
 *                     Stock is posted only on acceptance, never at return time.
 *  REMOVED_COMPONENT  an old component taken off the vehicle (Removed Components), handled by
 *                     Used Sparepart Processing: PENDING_RETURN -> PENDING_INSPECTION -> ...
 *  USED_PART          legacy used-condition returns already in Used Sparepart Processing.
 *
 * Forward-safe and additive. Backfill: every existing row gets a source (RESTOCKED rows are
 * NEW_PART, rows already in the disposition workflow stay USED_PART), a work_order_id, and
 * NEW_PART rows a legacy Return Number (RTN-LEGACY-000001 per tenant, by creation order).
 * Every existing Removed Component gets its Used Sparepart Processing row (PENDING_RETURN, or
 * PENDING_INSPECTION when it was already returned to a warehouse). No stock is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_order_part_returns ALTER COLUMN work_order_planned_part_id DROP NOT NULL');
        DB::statement('ALTER TABLE work_order_part_returns ALTER COLUMN warehouse_id DROP NOT NULL');

        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->string('return_number', 60)->nullable()->after('id');
            $table->string('return_source', 30)->default('NEW_PART')->after('return_number');
            $table->uuid('work_order_id')->nullable()->after('tenant_id');
            $table->uuid('work_order_removed_component_id')->nullable()->after('work_order_planned_part_id');
            $table->string('actual_condition', 30)->nullable()->after('condition');
            $table->string('inspection_result', 20)->nullable()->after('inspection_notes');

            $table->foreign('work_order_id')->references('id')->on('work_orders')->noActionOnDelete();
            $table->foreign('work_order_removed_component_id', 'wo_part_returns_removed_component_fk')->references('id')->on('work_order_removed_components')->cascadeOnDelete();
            $table->unique('work_order_removed_component_id', 'wo_part_returns_removed_component_unique');
            $table->index(['tenant_id', 'return_source', 'disposition_status']);
        });

        DB::statement("CREATE UNIQUE INDEX wo_part_returns_tenant_number_unique ON work_order_part_returns (tenant_id, return_number) WHERE return_number IS NOT NULL");
        DB::statement("ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_return_source_check CHECK (return_source IN ('NEW_PART','REMOVED_COMPONENT','USED_PART'))");
        DB::statement("ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_actual_condition_check CHECK (actual_condition IS NULL OR actual_condition IN ('UNUSED_NEW','UNUSED_FAULTY'))");
        DB::statement("ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_inspection_result_check CHECK (inspection_result IS NULL OR inspection_result IN ('MATCH','MISMATCH'))");
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_disposition_status_check');
        DB::statement("ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_disposition_status_check CHECK (disposition_status IN ('PENDING_PROCESSING','QUARANTINED','PENDING_RETURN','RESTOCKED','PENDING_INSPECTION','INSPECTED','PENDING_APPROVAL','REJECTED','FINALIZED'))");
        // A row is either an issued-part return or a removed-component record, never both.
        DB::statement('ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_origin_check CHECK ((work_order_planned_part_id IS NULL) <> (work_order_removed_component_id IS NULL))');

        $this->backfill();
        $this->grantPermissions();
    }

    /**
     * part_return.view -> roles that could return parts or view used-part processing before;
     * part_return.process -> roles that inspect returned parts (used_part.inspect).
     */
    private function grantPermissions(): void
    {
        foreach (['part_return.view' => ['inventory.return', 'used_part.view'], 'part_return.process' => ['used_part.inspect']] as $name => $sources) {
            $id = DB::table('permissions')->where('name', $name)->where('scope', 'tenant')->value('id');
            if ($id === null) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $id, 'name' => $name, 'group' => 'part_return', 'scope' => 'tenant',
                    'description' => $name === 'part_return.view' ? 'View part returns' : 'Process returned parts',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $sourceIds = DB::table('permissions')->whereIn('name', $sources)->where('scope', 'tenant')->pluck('id');
            $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
            $rows = DB::table('role_permissions')->whereIn('permission_id', $sourceIds)->distinct()->pluck('role_id')
                ->reject(fn ($roleId) => in_array($roleId, $granted, true))
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
                ->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function backfill(): void
    {
        // Source + Work Order of every existing issued-part return.
        DB::statement("UPDATE work_order_part_returns SET return_source = CASE WHEN disposition_status = 'RESTOCKED' THEN 'NEW_PART' ELSE 'USED_PART' END WHERE work_order_planned_part_id IS NOT NULL AND work_order_id IS NULL");
        DB::statement('UPDATE work_order_part_returns r SET work_order_id = p.work_order_id FROM work_order_planned_parts p WHERE p.id = r.work_order_planned_part_id AND r.work_order_id IS NULL');

        // Legacy Return Numbers for NEW_PART rows, per tenant, by creation order.
        foreach (DB::table('work_order_part_returns')->where('return_source', 'NEW_PART')->whereNull('return_number')->distinct()->pluck('tenant_id') as $tenantId) {
            $next = DB::table('work_order_part_returns')->where('tenant_id', $tenantId)->where('return_number', 'like', 'RTN-LEGACY-%')->count() + 1;
            foreach (DB::table('work_order_part_returns')->where('tenant_id', $tenantId)->where('return_source', 'NEW_PART')->whereNull('return_number')->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                DB::table('work_order_part_returns')->where('id', $id)->update(['return_number' => 'RTN-LEGACY-'.str_pad((string) $next++, 6, '0', STR_PAD_LEFT)]);
            }
        }

        // Used Sparepart Processing row for every Removed Component that has none yet.
        $components = DB::table('work_order_removed_components as c')
            ->leftJoin('work_order_removed_component_returns as ret', 'ret.work_order_removed_component_id', '=', 'c.id')
            ->whereNotExists(fn ($q) => $q->from('work_order_part_returns as r')->whereColumn('r.work_order_removed_component_id', 'c.id'))
            ->get(['c.id', 'c.tenant_id', 'c.work_order_id', 'c.product_id', 'c.quantity', 'c.condition', 'c.status', 'c.removed_by', 'c.removed_at', 'ret.warehouse_id']);
        foreach ($components as $c) {
            DB::table('work_order_part_returns')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $c->tenant_id, 'work_order_id' => $c->work_order_id,
                'return_source' => 'REMOVED_COMPONENT', 'work_order_removed_component_id' => $c->id,
                'product_id' => $c->product_id, 'quantity' => $c->quantity, 'warehouse_id' => $c->warehouse_id,
                'condition' => $c->condition === 'GOOD' ? 'USED_GOOD' : 'USED_FAULTY',
                'disposition_status' => $c->status === 'RETURNED' ? 'PENDING_INSPECTION' : 'PENDING_RETURN',
                'returned_by' => $c->removed_by, 'created_at' => $c->removed_at, 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('work_order_part_returns')->where('return_source', 'REMOVED_COMPONENT')->delete();
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT IF EXISTS work_order_part_returns_origin_check');
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT IF EXISTS work_order_part_returns_return_source_check');
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT IF EXISTS work_order_part_returns_actual_condition_check');
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT IF EXISTS work_order_part_returns_inspection_result_check');
        DB::statement('DROP INDEX IF EXISTS wo_part_returns_tenant_number_unique');
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->dropForeign(['work_order_id']);
            $table->dropForeign('wo_part_returns_removed_component_fk');
            $table->dropUnique('wo_part_returns_removed_component_unique');
            $table->dropIndex(['tenant_id', 'return_source', 'disposition_status']);
            $table->dropColumn(['return_number', 'return_source', 'work_order_id', 'work_order_removed_component_id', 'actual_condition', 'inspection_result']);
        });
    }
};
