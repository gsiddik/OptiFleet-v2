<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Workspace Assignment (workspace_reservations) lifecycle, owner decision:
 * RESERVED (requested) → APPROVED (workspace.approve) → COMPLETED (only when its Work Order
 * completes); an approved assignment can be TRANSFERRED to another workspace (the new assignment
 * is approved immediately and links back via transferred_from_id); CANCELLED as before. Legacy
 * ACTIVE rows stay valid and count as approved.
 *
 * One current assignment (RESERVED / APPROVED / ACTIVE) per Work Order — enforced by a partial
 * unique index. Pre-existing duplicates (older open reservations of the same Work Order) are
 * cancelled, not deleted, before the index is created.
 */
return new class extends Migration
{
    private const OLD_STATUSES = ['RESERVED', 'ACTIVE', 'COMPLETED', 'CANCELLED'];

    private const STATUSES = ['RESERVED', 'APPROVED', 'ACTIVE', 'TRANSFERRED', 'COMPLETED', 'CANCELLED'];

    public function up(): void
    {
        DB::statement('ALTER TABLE workspace_reservations DROP CONSTRAINT IF EXISTS workspace_reservations_status_check');
        DB::statement("ALTER TABLE workspace_reservations ADD CONSTRAINT workspace_reservations_status_check CHECK (status IN ('".implode("','", self::STATUSES)."'))");

        Schema::table('workspace_reservations', function (Blueprint $table) {
            $table->uuid('approved_by')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->uuid('transferred_from_id')->nullable()->after('approved_at');
            $table->uuid('transferred_by')->nullable()->after('transferred_from_id');
            $table->timestamp('transferred_at')->nullable()->after('transferred_by');
            $table->timestamp('completed_at')->nullable()->after('transferred_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('transferred_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('transferred_from_id')->references('id')->on('workspace_reservations')->nullOnDelete();
            $table->index(['work_order_id', 'status']);
        });

        // Keep the newest open reservation of each Work Order; older open duplicates are cancelled.
        DB::statement("
            UPDATE workspace_reservations r SET status = 'CANCELLED', cancelled_at = now()
            WHERE r.work_order_id IS NOT NULL AND r.status IN ('RESERVED', 'ACTIVE')
              AND EXISTS (
                SELECT 1 FROM workspace_reservations n
                WHERE n.work_order_id = r.work_order_id AND n.status IN ('RESERVED', 'ACTIVE')
                  AND (n.created_at, n.id) > (r.created_at, r.id)
              )
        ");
        DB::statement("CREATE UNIQUE INDEX workspace_reservations_one_current_per_wo ON workspace_reservations (work_order_id) WHERE work_order_id IS NOT NULL AND status IN ('RESERVED', 'APPROVED', 'ACTIVE')");
        DB::statement('ALTER TABLE workspace_reservations ADD CONSTRAINT workspace_reservations_window_check CHECK (end_at > start_at)');
        DB::statement("ALTER TABLE workspace_reservations ADD CONSTRAINT workspace_reservations_approved_check CHECK (status <> 'APPROVED' OR approved_at IS NOT NULL)");

        $this->grant('workspace.approve', 'workspace.reserve', 'Approve, and transfer, Workspace Assignments');
    }

    private function grant(string $name, string $source, string $description): void
    {
        $id = DB::table('permissions')->where('name', $name)->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => $name, 'group' => Str::before($name, '.'), 'scope' => 'tenant',
                'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $sourceId = DB::table('permissions')->where('name', $source)->where('scope', 'tenant')->value('id');
        if ($sourceId === null) {
            return;
        }
        $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
        $rows = DB::table('role_permissions')->where('permission_id', $sourceId)->distinct()->pluck('role_id')
            ->reject(fn ($roleId) => in_array($roleId, $granted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS workspace_reservations_one_current_per_wo');
        DB::statement('ALTER TABLE workspace_reservations DROP CONSTRAINT IF EXISTS workspace_reservations_window_check');
        DB::statement('ALTER TABLE workspace_reservations DROP CONSTRAINT IF EXISTS workspace_reservations_approved_check');
        // Rows in the new statuses map back to their closest legacy meaning.
        DB::table('workspace_reservations')->where('status', 'APPROVED')->update(['status' => 'ACTIVE']);
        DB::table('workspace_reservations')->where('status', 'TRANSFERRED')->update(['status' => 'COMPLETED']);
        DB::statement('ALTER TABLE workspace_reservations DROP CONSTRAINT IF EXISTS workspace_reservations_status_check');
        DB::statement("ALTER TABLE workspace_reservations ADD CONSTRAINT workspace_reservations_status_check CHECK (status IN ('".implode("','", self::OLD_STATUSES)."'))");
        Schema::table('workspace_reservations', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['transferred_by']);
            $table->dropForeign(['transferred_from_id']);
            $table->dropIndex(['work_order_id', 'status']);
            $table->dropColumn(['approved_by', 'approved_at', 'transferred_from_id', 'transferred_by', 'transferred_at', 'completed_at', 'cancelled_at']);
        });
        $id = DB::table('permissions')->where('name', 'workspace.approve')->value('id');
        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
