<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeded-but-unenforced permissions that now gate an EXISTING action are
 * granted to every role that could already perform it, so no existing user
 * loses access. Idempotent.
 *
 *  - POST /platform/subscriptions/{id}/generate-billing also generates and
 *    issues the invoice: it now requires invoice.generate + invoice.issue on
 *    top of billing.generate.
 *  - Cancelling from the Workshop Invoice page runs the same cancel as the
 *    External Work Order (work_order.cancel_external) and is gated by
 *    external_work_order_invoice.cancel.
 *
 * Permissions that gate a NEW action (contract.update, work_order.update,
 * inspection.review, subscription.activate, billing.adjust,
 * intelligence.prediction.run, intelligence.model.evaluate) are not backfilled:
 * nobody performed those actions before, and roles already holding them keep them.
 */
return new class extends Migration
{
    private const GRANTS = [
        ['platform', 'billing.generate', 'invoice.generate', 'Generate invoice'],
        ['platform', 'billing.generate', 'invoice.issue', 'Issue invoice'],
        ['tenant', 'work_order.cancel_external', 'external_work_order_invoice.cancel', 'Cancel external work order invoice'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as [$scope, $source, $target, $description]) {
            $targetId = DB::table('permissions')->where('name', $target)->where('scope', $scope)->value('id');
            if ($targetId === null) {
                $targetId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $targetId, 'name' => $target, 'group' => Str::beforeLast($target, '.'), 'scope' => $scope,
                    'description' => $description, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $sourceId = DB::table('permissions')->where('name', $source)->where('scope', $scope)->value('id');
            if ($sourceId === null) {
                continue;
            }

            $granted = DB::table('role_permissions')->where('permission_id', $targetId)->pluck('role_id')->all();
            $rows = DB::table('role_permissions')->where('permission_id', $sourceId)->pluck('role_id')
                ->reject(fn ($roleId) => in_array($roleId, $granted, true))
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $targetId, 'created_at' => now(), 'updated_at' => now()])
                ->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Permission rows are owned by PermissionSeeder; grants are kept.
    }
};
