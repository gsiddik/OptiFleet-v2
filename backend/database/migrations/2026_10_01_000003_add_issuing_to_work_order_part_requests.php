<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Part Requests become the only issuing path: Work Order "Reserve" -> REQUESTED ->
 * APPROVED / REJECTED / CANCELLED -> (APPROVED only) ISSUED.
 *
 *  - status gains ISSUED; warehouse_id / issued_by / issued_at record the issue.
 *  - One request line per planned part (unique), so a line can never be issued twice
 *    through two requests.
 *  - part_request.issue is granted to every role that could issue before (inventory.issue).
 *  - Legacy planned-part lines (created before this change, not linked to any request) that
 *    still have an un-issued quantity are wrapped in an APPROVED Part Request, so they stay
 *    issuable through the one remaining path instead of getting stuck. Only lines with a
 *    catalog product on a Work Order that is still executable are migrated. Idempotent.
 */
return new class extends Migration
{
    // Mirrors WorkOrderExecutionService::EXECUTABLE_STATUSES (where issuing is allowed).
    private const EXECUTABLE = ['ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'];

    public function up(): void
    {
        DB::statement('ALTER TABLE work_order_part_requests DROP CONSTRAINT IF EXISTS work_order_part_requests_status_check');
        DB::statement("ALTER TABLE work_order_part_requests ADD CONSTRAINT work_order_part_requests_status_check CHECK (status::text = ANY (ARRAY['REQUESTED','APPROVED','REJECTED','CANCELLED','ISSUED']::varchar[]))");

        Schema::table('work_order_part_requests', function (Blueprint $table) {
            $table->uuid('warehouse_id')->nullable()->after('decision_note');
            $table->uuid('issued_by')->nullable()->after('warehouse_id');
            $table->timestamp('issued_at')->nullable()->after('issued_by');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->noActionOnDelete();
        });

        Schema::table('work_order_part_request_items', function (Blueprint $table) {
            $table->unique('planned_part_id', 'wo_part_req_items_planned_part_unique');
        });

        $this->grantIssuePermission();
        $this->wrapLegacyPlannedParts();
    }

    private function grantIssuePermission(): void
    {
        $issueId = DB::table('permissions')->where('name', 'part_request.issue')->where('scope', 'tenant')->value('id');
        if ($issueId === null) {
            $issueId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $issueId, 'name' => 'part_request.issue', 'group' => 'part_request', 'scope' => 'tenant',
                'description' => 'Issue an approved part request', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $sourceId = DB::table('permissions')->where('name', 'inventory.issue')->where('scope', 'tenant')->value('id');
        if ($sourceId === null) {
            return;
        }
        $granted = DB::table('role_permissions')->where('permission_id', $issueId)->pluck('role_id')->all();
        $rows = DB::table('role_permissions')->where('permission_id', $sourceId)->pluck('role_id')
            ->reject(fn ($roleId) => in_array($roleId, $granted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $issueId, 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function wrapLegacyPlannedParts(): void
    {
        $lines = DB::table('work_order_planned_parts as p')
            ->join('work_orders as w', 'w.id', '=', 'p.work_order_id')
            ->whereNotNull('p.product_id')
            ->where('p.status', '!=', 'CANCELLED')
            ->whereColumn('p.planned_quantity', '>', 'p.issued_quantity')
            ->whereIn('w.status', self::EXECUTABLE)
            ->whereNotExists(fn ($q) => $q->from('work_order_part_request_items as i')->whereColumn('i.planned_part_id', 'p.id'))
            ->orderBy('p.created_at')
            ->get(['p.id', 'p.tenant_id', 'p.work_order_id', 'p.product_id', 'p.product_reference', 'p.description', 'p.planned_quantity', 'p.issued_quantity']);

        foreach ($lines->groupBy('work_order_id') as $workOrderId => $workOrderLines) {
            $requestId = (string) Str::uuid();
            DB::table('work_order_part_requests')->insert([
                'id' => $requestId, 'tenant_id' => $workOrderLines->first()->tenant_id, 'work_order_id' => $workOrderId,
                'notes' => 'Migrated: planned parts created before issuing moved to Part Requests.',
                'status' => 'APPROVED', 'requested_at' => now(), 'decided_at' => now(),
                'decision_note' => 'Approved automatically on migration (lines were already planned).',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($workOrderLines as $line) {
                $remaining = (float) $line->planned_quantity - (float) $line->issued_quantity;
                DB::table('work_order_part_request_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $line->tenant_id, 'part_request_id' => $requestId,
                    'product_id' => $line->product_id, 'product_reference' => $line->product_reference, 'description' => $line->description,
                    'quantity_requested' => $remaining, 'quantity_approved' => $remaining, 'planned_part_id' => $line->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('work_order_part_request_items', function (Blueprint $table) {
            $table->dropUnique('wo_part_req_items_planned_part_unique');
        });
        Schema::table('work_order_part_requests', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['warehouse_id', 'issued_by', 'issued_at']);
        });
        DB::statement('ALTER TABLE work_order_part_requests DROP CONSTRAINT IF EXISTS work_order_part_requests_status_check');
        DB::statement("ALTER TABLE work_order_part_requests ADD CONSTRAINT work_order_part_requests_status_check CHECK (status::text = ANY (ARRAY['REQUESTED','APPROVED','REJECTED','CANCELLED']::varchar[]))");
    }
};
