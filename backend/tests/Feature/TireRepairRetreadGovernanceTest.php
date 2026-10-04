<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireRepair;
use App\Domain\Tire\Models\TireRetread;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase E — Tire Repair/Retread Governance: G-27 (distinct REPAIR
 * lifecycle), G-29 (concurrency-safe cycle handling), G-30 (eligible
 * partner validation), G-32 (maker-checker), G-33 (persisted disposition
 * reasons), G-36 (final-inspection gate before returning to stock),
 * G-37 (auditable lifecycle records).
 */
class TireRepairRetreadGovernanceTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TRRG-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        return [$tenant, $vehicle, $product, $partner];
    }

    private function allPermissions(): array
    {
        return [
            'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap',
            'tire_retread.send', 'tire_retread.receive', 'tire_retread.inspect', 'tire_retread.approve',
            'tire_repair.send', 'tire_repair.receive', 'tire_repair.inspect', 'tire_repair.approve',
        ];
    }

    /** Installs and removes a tire with the given disposition, returning the tire fresh. */
    private function removeForCycle(Tire $tire, $vehicle, string $disposition, array $headers): Tire
    {
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Scheduled service', 'disposition' => $disposition], $headers)->assertStatus(201);

        return $tire->fresh();
    }

    // ---- G-27: distinct REPAIR lifecycle -------------------------------------------------

    public function test_repair_disposition_is_distinct_from_retread(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-REPAIR-DISP', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $headers);

        $this->assertSame('REPAIR', $tire->current_status);
        $this->assertSame('REPAIR', $tire->removals()->first()->disposition);
    }

    public function test_repair_and_retread_cycles_are_tracked_in_separate_tables(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-REPAIR-TABLE', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $partner->id], $headers)->assertStatus(201);

        $this->assertSame(1, TireRepair::query()->where('tire_id', $tire->id)->count());
        $this->assertSame(0, TireRetread::query()->where('tire_id', $tire->id)->count());
    }

    public function test_sending_for_repair_requires_repair_status(): void
    {
        [$tenant, , $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-REPAIR-BADSTATUS', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    // ---- G-29: concurrency-safe cycle handling -------------------------------------------

    public function test_second_retread_send_is_rejected_while_a_cycle_is_still_open(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-DOUBLE-SEND', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    public function test_cycle_numbers_increment_across_successive_retread_cycles_for_the_same_tire(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [$owner, $ownerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        [$checker, $checkerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $ownerHeaders = $this->authHeaders($ownerToken);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-CYCLE-SEQ', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $ownerHeaders);

        $first = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $ownerHeaders)->assertStatus(201);
        $this->assertSame(1, $first->json('data.cycle_number'));
        $retreadId = $first->json('data.id');

        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $ownerHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'SAFE'], $checkerHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", ['disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Passed final inspection'], $checkerHeaders)->assertOk();
        // Back to REMOVED for the mandatory Used Tire Management inspection; it passed (REUSE).
        $this->assertSame('REMOVED', $tire->fresh()->current_status);
        $tire->fresh()->update(['current_status' => 'REUSE']);

        $tire = $this->removeForCycle($tire->fresh(), $vehicle, 'RETREAD', $ownerHeaders);
        $second = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $ownerHeaders)->assertStatus(201);
        $this->assertSame(2, $second->json('data.cycle_number'));
    }

    // ---- G-30: eligible partner validation ------------------------------------------------

    public function test_ineligible_partner_type_is_rejected_for_retread(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        $ineligiblePartner = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER']);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-INELIGIBLE', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $ineligiblePartner->id], $headers)->assertStatus(422);
    }

    public function test_inactive_partner_is_rejected_for_repair(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        $inactivePartner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP', 'status' => 'INACTIVE']);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-INACTIVE-PARTNER', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $inactivePartner->id], $headers)->assertStatus(422);
    }

    public function test_eligible_active_partner_is_accepted(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        $eligiblePartner = $this->makePartner($tenant, ['partner_type' => 'TIRE_SUPPLIER', 'status' => 'ACTIVE']);
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-ELIGIBLE', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $eligiblePartner->id], $headers)->assertStatus(201);
    }

    // ---- Separate send/receive/inspect/approve permissions -------------------------------

    public function test_sending_for_retread_requires_send_permission(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $fullToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        [, $noSendToken] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire_retread.receive', 'tire_retread.inspect', 'tire_retread.approve']);
        $fullHeaders = $this->authHeaders($fullToken);
        $noSendHeaders = $this->authHeaders($noSendToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-NOSEND', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $fullHeaders);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $noSendHeaders)->assertStatus(403);
    }

    // ---- G-36: final-inspection gate before returning to stock ---------------------------

    public function test_receiving_a_retread_does_not_return_tire_to_stock(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-RECEIVE-GATE', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');

        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();

        $tire->refresh();
        $this->assertSame('UNDER_INSPECTION', $tire->current_status);
        $this->assertNotSame('IN_STOCK', $tire->current_status);
    }

    public function test_quarantined_tire_after_repair_cannot_be_installed(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [$sender, $senderToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        [$approver, $approverToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $senderHeaders = $this->authHeaders($senderToken);
        $approverHeaders = $this->authHeaders($approverToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-QUARANTINE', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'REPAIR', $senderHeaders);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/repair", ['partner_id' => $partner->id], $senderHeaders)->assertStatus(201);
        $repairId = $send->json('data.id');

        $this->postJson("/api/v1/app/tires/{$tire->id}/repairs/{$repairId}/receive", [], $senderHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/repairs/{$repairId}/final-inspect", ['result' => 'UNSAFE', 'notes' => 'Structural damage found'], $approverHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/repairs/{$repairId}/approve", ['disposition' => 'QUARANTINE', 'reason' => 'Unsafe casing, pending disposal decision'], $approverHeaders)->assertOk();

        $tire->refresh();
        // The QUARANTINE disposition is the HOLD status of the used-tire lifecycle.
        $this->assertSame('HOLD', $tire->current_status);

        // No alternate endpoint can install a HOLD tire — install() only accepts new stock or REUSE.
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_RIGHT'], $senderHeaders)->assertStatus(422);
    }

    public function test_unsafe_final_inspection_cannot_be_approved_for_return_to_service(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-UNSAFE-BLOCK', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'UNSAFE'], $headers)->assertOk();

        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Attempting unsafe return',
        ], $checkerHeaders)->assertStatus(422);
    }

    public function test_final_inspection_cannot_be_skipped_before_approval(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SKIP-INSPECT', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Skipping ahead',
        ], $headers)->assertStatus(422);
    }

    // ---- G-32: maker-checker (approver distinct from receiver) --------------------------

    public function test_receiver_cannot_also_approve_the_same_cycle(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SELFAPPROVE', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');

        // Same actor receives and then also inspects/approves.
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'SAFE'], $headers)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Self-approval attempt',
        ], $headers)->assertStatus(422);
    }

    public function test_distinct_receiver_and_approver_succeeds_and_returns_tire_to_stock(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [$receiver, $receiverToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        [$approver, $approverToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $receiverHeaders = $this->authHeaders($receiverToken);
        $approverHeaders = $this->authHeaders($approverToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-DISTINCT-OK', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $receiverHeaders);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $receiverHeaders)->assertStatus(201);
        $retreadId = $send->json('data.id');

        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $receiverHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'SAFE', 'notes' => 'Tread within spec'], $approverHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Passed inspection, returning to service',
        ], $approverHeaders)->assertOk();

        $tire->refresh();
        // Returned to REMOVED: it is inspected again in Used Tire Management before reuse.
        $this->assertSame('REMOVED', $tire->current_status);

        $retread = TireRetread::query()->findOrFail($retreadId);
        $this->assertSame('APPROVED', $retread->status);
        $this->assertSame($receiver->id, $retread->received_by);
        $this->assertSame($approver->id, $retread->approved_by);
    }

    // ---- G-33: persisted disposition reasons ----------------------------------------------

    public function test_approval_reason_is_required_and_persisted(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-REASON', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'SAFE'], $headers)->assertOk();

        // Missing reason entirely.
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE',
        ], $checkerHeaders)->assertStatus(422);

        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Meets return-to-service criteria',
        ], $checkerHeaders)->assertOk();

        $this->assertSame('Meets return-to-service criteria', TireRetread::query()->findOrFail($retreadId)->approval_reason);
    }

    // ---- G-37: auditable lifecycle records -------------------------------------------------

    public function test_retread_lifecycle_changes_are_audited(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->allPermissions(), ['audit.view']));
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-AUDIT', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);

        $auditCount = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('resource_type', 'TireRetread')
            ->count();
        $this->assertGreaterThan(0, $auditCount);
    }

    // ---- Phase D permissive wheel-position fallback: documented, not fixed by Phase E ------

    /**
     * G-36's approval gate only decides whether a tire may re-enter the
     * IN_STOCK pool — it does not perform or revalidate an installation.
     * Phase D's G-25 position check remains permissive for any vehicle
     * category with zero configured wheel_configurations rows, and that
     * gap is untouched by Phase E: a tire returned to service can still be
     * installed to an arbitrary, unvalidated position on such a vehicle.
     * This test documents that the two controls are orthogonal — approval
     * is not a substitute for verified position integrity — per the task's
     * explicit instruction not to silently treat the Phase D fallback as
     * resolved. See IMPROVEMENT_CONTEXT.md's Phase E notes for the
     * accompanying decision record.
     */
    public function test_return_to_service_approval_does_not_revalidate_wheel_position_on_next_install(): void
    {
        [$tenant, $vehicle, $product, $partner] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->allPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->allPermissions());
        $headers = $this->authHeaders($token);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-POSGAP', 'current_status' => 'IN_STOCK']);
        $tire = $this->removeForCycle($tire, $vehicle, 'RETREAD', $headers);
        $send = $this->postJson("/api/v1/app/tires/{$tire->id}/retread", ['partner_id' => $partner->id], $headers)->assertStatus(201);
        $retreadId = $send->json('data.id');
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/receive", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/final-inspect", ['result' => 'SAFE'], $checkerHeaders)->assertOk();
        $this->postJson("/api/v1/app/tires/{$tire->id}/retreads/{$retreadId}/approve", [
            'disposition' => 'RETURN_TO_SERVICE', 'reason' => 'Passed final inspection',
        ], $checkerHeaders)->assertOk();

        $tire->refresh();
        // A returned tire is inspected again in Used Tire Management before reuse.
        $this->assertSame('REMOVED', $tire->current_status);
        $tire->update(['current_status' => 'REUSE']);

        // No wheel_configurations rows exist for this vehicle's category — install() stays
        // permissive (Phase D, G-25), unaffected by the Phase E approval that just ran.
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'UNVALIDATED_POS',
        ], $headers)->assertStatus(201);
    }
}
