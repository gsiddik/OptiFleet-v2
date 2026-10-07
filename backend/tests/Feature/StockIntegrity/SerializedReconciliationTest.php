<?php

namespace Tests\Feature\StockIntegrity;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\Inventory\Models\InstallationStockExit;
use App\Domain\Inventory\Models\StockReconciliationAdjustment;
use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Inventory\Services\StockReconciliationAdjustmentService;
use App\Domain\Inventory\Services\SerializedStockReconciliationService;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reconciliation of serialized units installed BEFORE installations settled with the ledger. The
 * fixture is deliberately inconsistent (legacy installations written without an exit record and
 * without a ledger issue); the dry-run must not change anything, only provable cases are corrected,
 * and a correction cannot be applied twice.
 */
class SerializedReconciliationTest extends TestCase
{
    private function tenantScenario(string $prefix): array
    {
        $tenant = $this->makeTenant(['code' => $prefix.'-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'PROCUREMENT', 'PARTNER', 'COMPONENT', 'WORKSHOP', 'WORK_ORDER', 'MAINTENANCE', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 1 '.Str::upper(Str::random(3)), 'default_workshop_id' => $workshop->id]);
        $group = $this->makeComponentGroup();
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Battery '.Str::random(4), 'product_type' => 'SPARE_PART', 'track_serial_number' => true]);
        DB::table('product_component_groups')->insert(['product_id' => $product->id, 'component_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
        $po = PurchaseOrder::query()->create([
            'tenant_id' => $tenant->id, 'po_number' => 'PO/REC/'.Str::random(6), 'partner_id' => $this->makePartner($tenant)->id, 'delivery_warehouse_id' => $warehouse->id,
            'status' => 'ISSUED', 'order_date' => '2026-10-01', 'subtotal' => 4500000, 'tax_total' => 0, 'freight_cost' => 0, 'total' => 4500000,
        ]);
        $line = PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity_ordered' => 3, 'unit_price' => 1500000, 'line_total' => 4500000]);
        [, $token] = $this->makeTenantUser($tenant, ['goods_receipt.post', 'goods_receipt.view', 'purchase_order.view']);
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $line->id, 'quantity_accepted' => 3]], 'invoice_mode' => 'NEW',
            'vendor_invoice_number' => 'INV-'.Str::random(6), 'vendor_invoice_date' => '2026-10-02', 'amount' => '1000', 'terms_of_payment_days' => '30',
        ], $this->authHeaders($token))->assertStatus(201);
        $assets = ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->orderBy('asset_number')->get();

        return compact('tenant', 'branch', 'workshop', 'warehouse', 'vehicle', 'product', 'assets');
    }

    /** A legacy installation: the unit is INSTALLED, no exit record, the ledger untouched. */
    private function legacyInstall(array $s, ComponentAsset $asset, ?string $workOrderId = null, string $at = '2026-08-10 09:00:00'): ComponentInstallation
    {
        $installation = ComponentInstallation::query()->create([
            'tenant_id' => $s['tenant']->id, 'component_asset_id' => $asset->id, 'vehicle_id' => $s['vehicle']->id, 'position_location' => 'A',
            'installed_at' => $at, 'work_order_id' => $workOrderId,
        ]);
        $asset->update(['current_status' => 'INSTALLED', 'current_vehicle_id' => $s['vehicle']->id, 'current_warehouse_id' => null]);

        return $installation;
    }

    private function onHand(array $s): float
    {
        return (float) DB::table('warehouse_stocks')->where('warehouse_id', $s['warehouse']->id)->where('product_id', $s['product']->id)->value('quantity_on_hand');
    }

    /** Ledger 3 after the receipt: unit 1 installed with no issue (undeducted), unit 2 installed on a WO that had issued it (covered), unit 3 in stock. */
    private function inconsistentFixture(string $prefix = 'REC'): array
    {
        $s = $this->tenantScenario($prefix);
        [$a1, $a2] = [$s['assets'][0], $s['assets'][1]];
        $wo = app(WorkOrderService::class)->create($s['vehicle'], ['workshop_id' => $s['workshop']->id, 'maintenance_type' => 'CORRECTIVE'], null);
        DB::table('work_orders')->where('id', $wo->id)->update(['status' => 'IN_PROGRESS']);
        $part = WorkOrderPlannedPart::query()->create(['tenant_id' => $s['tenant']->id, 'work_order_id' => $wo->id, 'product_id' => $s['product']->id, 'warehouse_id' => $s['warehouse']->id,
            'description' => 'Battery', 'quantity' => 1, 'planned_quantity' => 1, 'stock_condition' => 'NEW', 'status' => 'PLANNED']);
        app(WorkOrderPartService::class)->issue($part->fresh(), null, null); // ledger 3 → 2, as it always was
        $s['undeducted'] = $this->legacyInstall($s, $a1);
        $s['covered'] = $this->legacyInstall($s, $a2, $wo->id, '2026-08-11 09:00:00');

        return $s;
    }

    private function movementCount(): int
    {
        return DB::table('stock_movements')->count();
    }

    public function test_dry_run_classifies_every_installation_and_changes_nothing(): void
    {
        $s = $this->inconsistentFixture();
        // Ambiguous: a component without a Goods Receipt link; and tires: one registered on the vehicle, one standard.
        $noReceipt = ComponentAsset::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'LEGACY-1', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $s['vehicle']->id]);
        ComponentInstallation::query()->create(['tenant_id' => $s['tenant']->id, 'component_asset_id' => $noReceipt->id, 'vehicle_id' => $s['vehicle']->id, 'installed_at' => '2026-07-01 09:00:00']);
        $tireProduct = $this->makeProduct($s['tenant'], null, null, ['name' => 'Tire '.Str::random(4), 'product_type' => 'TIRE']);
        $registered = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $tireProduct->id, 'serial_number' => 'T-REG', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $s['vehicle']->id]);
        TireInstallation::query()->create(['tenant_id' => $s['tenant']->id, 'tire_id' => $registered->id, 'vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FL', 'installed_at' => '2026-07-02 09:00:00', 'installation_source' => 'INITIAL_REGISTRATION']);
        $standard = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $tireProduct->id, 'serial_number' => 'T-STD', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $s['vehicle']->id]);
        TireInstallation::query()->create(['tenant_id' => $s['tenant']->id, 'tire_id' => $standard->id, 'vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FR', 'installed_at' => '2026-07-03 09:00:00']);

        $before = [$this->movementCount(), InstallationStockExit::query()->count(), $this->onHand($s), DB::table('component_assets')->where('current_status', 'IN_STOCK')->count()];
        $plan = app(SerializedStockReconciliationService::class)->plan($s['tenant']->id);
        $this->assertSame($before, [$this->movementCount(), InstallationStockExit::query()->count(), $this->onHand($s), DB::table('component_assets')->where('current_status', 'IN_STOCK')->count()], 'a dry-run writes nothing');

        $tenant = $plan['tenants'][0];
        $byId = collect($tenant['candidates'])->keyBy('installation_id');
        $this->assertSame([], collect($tenant['candidates'])->filter(fn ($c) => trim((string) $c['evidence']) === '')->pluck('installation_id')->all(), 'every classification explains itself');
        $this->assertSame([], collect($tenant['candidates'])->filter(fn ($c) => empty($c['evidence_code']))->pluck('installation_id')->all(), 'and carries a code the UI translates');
        $this->assertSame('PROVABLE_UNDEDUCTED', $byId[$s['undeducted']->id]['category']);
        $this->assertSame('COVERED_BY_WO_ISSUE', $byId[$s['covered']->id]['category']);
        $this->assertSame('AMBIGUOUS_NO_RECEIPT', $byId[ComponentInstallation::query()->where('component_asset_id', $noReceipt->id)->value('id')]['category']);
        $this->assertSame('NOT_WAREHOUSE_ORIGIN', $byId[TireInstallation::query()->where('tire_id', $registered->id)->value('id')]['category']);
        $this->assertSame('AMBIGUOUS_TIRE', $byId[TireInstallation::query()->where('tire_id', $standard->id)->value('id')]['category']);
        $this->assertSame(['PROVABLE_UNDEDUCTED' => 1, 'COVERED_BY_WO_ISSUE' => 1, 'RESOLVED_BY_OPNAME' => 0, 'AMBIGUOUS_NO_RECEIPT' => 1, 'AMBIGUOUS_TIRE' => 1, 'NOT_WAREHOUSE_ORIGIN' => 1, 'CORRECTED_BY_RECONCILIATION' => 0], collect($tenant['summary'])->except('installations')->all());

        // The balance corroborates: ledger 2 vs 1 unit registered in stock = 1 difference, explained by the one provable case.
        $balance = collect($tenant['balance'])->firstWhere('product_id', $s['product']->id);
        $this->assertSame(['2.0000', 1, '1.0000', 1], [$balance['ledger_on_hand'], $balance['registered_in_stock_units'], $balance['difference'], $balance['explained_by_provable_undeducted']]);
    }

    private function api(array $s, array $perms, ?array $scopes = null): array
    {
        [$user, $token] = $this->makeTenantUser($s['tenant'], $perms, $scopes);

        return [$user, $this->authHeaders($token)];
    }

    private const BASE = '/api/v1/app/inventory/reconciliation/adjustments';

    private function propose(array $s, array $h, ?string $installationId = null)
    {
        return $this->postJson(self::BASE, ['installation_id' => $installationId ?? $s['undeducted']->id, 'reason' => 'Old install never issued'], $h);
    }

    private function snapshot(array $s): array
    {
        return [$this->movementCount(), InstallationStockExit::query()->count(), $this->onHand($s)];
    }

    private function approvedAdjustment(array $s): array
    {
        [, $maker] = $this->api($s, ['inventory_reconcile.view', 'inventory_reconcile.manage']);
        [$approver, $checker] = $this->api($s, ['inventory_reconcile.view', 'inventory_reconcile.approve']);
        $id = $this->propose($s, $maker)->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/{$id}/approve", ['note' => 'Checked against the receipt'], $checker)->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return [$id, $maker, $checker, $approver];
    }

    public function test_proposal_and_approval_never_move_stock_and_apply_needs_approval(): void
    {
        $s = $this->inconsistentFixture();
        $before = $this->snapshot($s);
        [, $maker] = $this->api($s, ['inventory_reconcile.view', 'inventory_reconcile.manage', 'inventory_reconcile.approve']);

        $id = $this->propose($s, $maker)->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL')->json('data.id');
        $this->assertSame($before, $this->snapshot($s), 'a proposal writes no movement and no exit');
        $this->assertSame('PROVABLE_UNDEDUCTED', StockReconciliationAdjustment::query()->findOrFail($id)->evidence['category']);
        $this->propose($s, $maker)->assertStatus(422); // a second live proposal for the same installation is refused (unique index)
        $this->assertSame(1, StockReconciliationAdjustment::query()->count());

        $this->postJson(self::BASE."/{$id}/apply", [], $maker)->assertStatus(422); // not approved
        $this->postJson(self::BASE."/{$id}/approve", [], $maker)->assertStatus(422); // maker is not the checker
        $this->assertSame($before, $this->snapshot($s));

        [, $checker] = $this->api($s, ['inventory_reconcile.approve']);
        $this->postJson(self::BASE."/{$id}/approve", [], $checker)->assertOk();
        $this->assertSame($before, $this->snapshot($s), 'approval alone does not move stock either');
    }

    public function test_rejected_adjustment_can_not_be_applied_and_needs_a_note(): void
    {
        $s = $this->inconsistentFixture();
        [, $maker] = $this->api($s, ['inventory_reconcile.manage']);
        [, $checker] = $this->api($s, ['inventory_reconcile.approve']);
        $id = $this->propose($s, $maker)->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/{$id}/reject", [], $checker)->assertStatus(422);
        $this->postJson(self::BASE."/{$id}/reject", ['note' => 'Unit was scrapped, not issued'], $checker)->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->postJson(self::BASE."/{$id}/apply", [], $maker)->assertStatus(422);
        $this->assertSame(2.0, $this->onHand($s));
        $this->propose($s, $maker)->assertCreated(); // a rejected proposal may be re-filed
    }

    public function test_apply_books_one_issue_now_keeps_history_and_can_not_run_twice(): void
    {
        $s = $this->inconsistentFixture();
        $installationBefore = DB::table('component_installations')->where('id', $s['undeducted']->id)->first();
        $movementsBefore = DB::table('stock_movements')->get()->keyBy('id');
        [$id, $maker] = $this->approvedAdjustment($s);

        $this->postJson(self::BASE."/{$id}/apply", [], $maker)->assertOk()->assertJsonPath('data.status', 'APPLIED');

        $this->assertSame(1.0, $this->onHand($s));
        $adj = StockReconciliationAdjustment::query()->findOrFail($id);
        $movement = DB::table('stock_movements')->where('id', $adj->stock_movement_id)->first();
        $this->assertSame(['ISSUE', StockReconciliationAdjustment::class, $id], [$movement->movement_type, $movement->reference_type, $movement->reference_id]);
        $this->assertTrue(\Carbon\Carbon::parse($movement->occurred_at)->greaterThan(now()->subMinute()), 'booked at correction time, never back-dated');
        $this->assertStringContainsString('2026-08-10', $movement->reason);
        $exit = InstallationStockExit::query()->where('installation_id', $s['undeducted']->id)->firstOrFail();
        $this->assertSame(['DIRECT_ISSUE', 'RECONCILED', $movement->id], [$exit->source, $exit->reason, $exit->stock_movement_id]);
        $this->assertNotNull($adj->applied_by);
        $this->assertNotNull($adj->decided_by);

        // History intact: the installation row and every earlier movement are byte-identical; exactly one movement was added.
        $this->assertEquals($installationBefore, DB::table('component_installations')->where('id', $s['undeducted']->id)->first());
        foreach ($movementsBefore as $mid => $row) {
            $this->assertEquals($row, DB::table('stock_movements')->where('id', $mid)->first());
        }
        $this->assertSame($movementsBefore->count() + 1, DB::table('stock_movements')->count());

        // Never twice: retry, a second proposal, and the report all agree.
        $this->postJson(self::BASE."/{$id}/apply", [], $maker)->assertStatus(422);
        $this->propose($s, $maker)->assertStatus(422);
        $this->assertSame(1.0, $this->onHand($s));
        $this->assertSame(1, DB::table('stock_movements')->where('reference_id', $id)->count());
        $tenant = app(SerializedStockReconciliationService::class)->plan($s['tenant']->id)['tenants'][0];
        $this->assertSame(1, $tenant['summary']['CORRECTED_BY_RECONCILIATION']);
        $this->assertSame(0, $tenant['summary']['PROVABLE_UNDEDUCTED']);
    }

    public function test_a_failed_apply_rolls_back_and_keeps_the_adjustment_approved(): void
    {
        $s = $this->inconsistentFixture();
        [$id, $maker] = $this->approvedAdjustment($s);
        DB::table('warehouse_stocks')->where('warehouse_id', $s['warehouse']->id)->where('product_id', $s['product']->id)->update(['quantity_on_hand' => 0]);

        $this->postJson(self::BASE."/{$id}/apply", [], $maker)->assertStatus(422);

        $this->assertSame(0.0, $this->onHand($s));
        $this->assertSame(0, InstallationStockExit::query()->count());
        $this->assertSame(0, DB::table('stock_movements')->where('reference_id', $id)->count());
        $this->assertSame('APPROVED', StockReconciliationAdjustment::query()->findOrFail($id)->status);
    }

    public function test_apply_after_the_unit_was_settled_elsewhere_is_superseded_without_booking(): void
    {
        $s = $this->inconsistentFixture();
        [$id, $maker] = $this->approvedAdjustment($s);
        // Someone settled the same installation meanwhile (exit record written by another path).
        InstallationStockExit::query()->create(['tenant_id' => $s['tenant']->id, 'asset_type' => 'COMPONENT_ASSET', 'asset_id' => $s['undeducted']->component_asset_id, 'installation_id' => $s['undeducted']->id,
            'product_id' => $s['product']->id, 'warehouse_id' => $s['warehouse']->id, 'source' => 'NOT_LEDGERED', 'reason' => 'NOT_NEW_STOCK']);
        $moves = $this->movementCount();

        $this->postJson(self::BASE."/{$id}/apply", [], $maker)->assertStatus(422);

        $this->assertSame('SUPERSEDED', StockReconciliationAdjustment::query()->findOrFail($id)->status);
        $this->assertSame($moves, $this->movementCount());
        $this->assertSame(2.0, $this->onHand($s));
    }

    public function test_only_provable_cases_can_be_proposed(): void
    {
        $s = $this->inconsistentFixture();
        [, $maker] = $this->api($s, ['inventory_reconcile.manage']);
        $this->propose($s, $maker, $s['covered']->id)->assertStatus(422); // covered by the WO issue
        $this->propose($s, $maker, (string) Str::uuid())->assertNotFound();
        $noReceipt = ComponentAsset::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'LEGACY-9', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $s['vehicle']->id]);
        $inst = ComponentInstallation::query()->create(['tenant_id' => $s['tenant']->id, 'component_asset_id' => $noReceipt->id, 'vehicle_id' => $s['vehicle']->id, 'installed_at' => '2026-07-01 09:00:00']);
        $this->propose($s, $maker, $inst->id)->assertNotFound(); // no warehouse known: nothing to scope or correct
        $this->assertSame(0, StockReconciliationAdjustment::query()->count());
        $this->assertSame(2.0, $this->onHand($s));
    }

    private function postedOpname(array $s, float $system, float $physical, string $created, string $posted): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_opnames')->insert(['id' => $id, 'tenant_id' => $s['tenant']->id, 'warehouse_id' => $s['warehouse']->id, 'opname_number' => 'SO-'.Str::random(5), 'status' => 'POSTED',
            'posted_at' => $posted, 'created_at' => $created, 'updated_at' => $posted]);
        DB::table('stock_opname_items')->insert(['id' => (string) Str::uuid(), 'stock_opname_id' => $id, 'product_id' => $s['product']->id, 'system_quantity' => $system, 'physical_quantity' => $physical,
            'created_at' => $created, 'updated_at' => $posted]);

        return $id;
    }

    public function test_a_later_posted_opname_resolves_the_case_and_is_reported_as_physical_evidence_only(): void
    {
        $s = $this->inconsistentFixture();
        $this->postedOpname($s, 3, 1, '2026-09-01 08:00:00', '2026-09-01 12:00:00');
        $c = collect(app(SerializedStockReconciliationService::class)->plan($s['tenant']->id)['tenants'][0]['candidates'])->firstWhere('installation_id', $s['undeducted']->id);

        $this->assertSame('RESOLVED_BY_OPNAME', $c['category']);
        $this->assertSame(['3.0000', '1.0000', '-2.0000', 'PHYSICAL_QUANTITY_AT_THAT_TIME'], [$c['opname']['system_quantity'], $c['opname']['physical_quantity'], $c['opname']['variance'], $c['opname']['proves']]);
        [, $maker] = $this->api($s, ['inventory_reconcile.manage']);
        $this->propose($s, $maker)->assertStatus(422);
        $this->assertSame(2.0, $this->onHand($s));
    }

    public function test_an_opname_older_than_the_installation_does_not_resolve_it_and_staleness_is_flagged(): void
    {
        $s = $this->inconsistentFixture();
        $this->postedOpname($s, 3, 3, '2026-08-01 08:00:00', '2026-08-01 12:00:00');
        $opname = app(SerializedStockReconciliationService::class)->latestPostedOpname($s['tenant']->id, $s['warehouse']->id, $s['product']->id);
        $this->assertFalse($opname['snapshot_stale']);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $s['tenant']->id, 'warehouse_id' => $s['warehouse']->id, 'product_id' => $s['product']->id,
            'movement_type' => 'ADJUSTMENT_PLUS', 'quantity' => 1, 'unit_cost' => 0, 'occurred_at' => '2026-08-01 10:00:00', 'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00']);
        $this->assertTrue(app(SerializedStockReconciliationService::class)->latestPostedOpname($s['tenant']->id, $s['warehouse']->id, $s['product']->id)['snapshot_stale'], 'a movement between snapshot and posting makes the variance stale');
        $c = collect(app(SerializedStockReconciliationService::class)->plan($s['tenant']->id)['tenants'][0]['candidates'])->firstWhere('installation_id', $s['undeducted']->id);
        $this->assertSame('PROVABLE_UNDEDUCTED', $c['category']);
        $this->assertTrue($c['opname']['snapshot_stale']);
    }

    public function test_ambiguous_tires_are_reported_with_warehouse_opname_evidence_and_never_adjusted(): void
    {
        $s = $this->inconsistentFixture();
        $tireProduct = $this->makeProduct($s['tenant'], null, null, ['name' => 'Tire '.Str::random(4), 'product_type' => 'TIRE']);
        app(\App\Domain\Inventory\Services\InventoryService::class)->receive($s['warehouse'], $tireProduct, 4, 900000, 'OPENING', null, null, null);
        $tire = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $tireProduct->id, 'serial_number' => 'T-AMB', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $s['vehicle']->id]);
        $inst = TireInstallation::query()->create(['tenant_id' => $s['tenant']->id, 'tire_id' => $tire->id, 'vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FL', 'installed_at' => '2026-07-03 09:00:00']);
        $opId = (string) Str::uuid();
        DB::table('stock_opnames')->insert(['id' => $opId, 'tenant_id' => $s['tenant']->id, 'warehouse_id' => $s['warehouse']->id, 'opname_number' => 'SO-TIRE', 'status' => 'POSTED', 'posted_at' => '2026-10-02 10:00:00', 'created_at' => '2026-10-02 08:00:00', 'updated_at' => now()]);
        DB::table('stock_opname_items')->insert(['id' => (string) Str::uuid(), 'stock_opname_id' => $opId, 'product_id' => $tireProduct->id, 'system_quantity' => 4, 'physical_quantity' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $c = collect(app(SerializedStockReconciliationService::class)->plan($s['tenant']->id)['tenants'][0]['candidates'])->firstWhere('installation_id', $inst->id);
        $this->assertSame('AMBIGUOUS_TIRE', $c['category'], 'an opname never turns a tire into a proven case');
        $this->assertSame($s['warehouse']->id, $c['warehouse_evidence'][0]['warehouse_id']);
        $this->assertSame(['4.0000', 'SO-TIRE', '-1.0000'], [$c['warehouse_evidence'][0]['system_quantity'], $c['warehouse_evidence'][0]['opname']['opname_number'], $c['warehouse_evidence'][0]['opname']['variance']]);
        [, $maker] = $this->api($s, ['inventory_reconcile.manage']);
        $this->propose($s, $maker, $inst->id)->assertNotFound();
        $this->assertSame(0, StockReconciliationAdjustment::query()->count());
    }

    public function test_permissions_scope_and_tenant_isolation(): void
    {
        $s = $this->inconsistentFixture('MIN');
        $other = $this->inconsistentFixture('OTH');
        [, $none] = $this->api($s, ['inventory.view']);
        $this->getJson('/api/v1/app/inventory/reconciliation', $none)->assertForbidden();
        $this->propose($s, $none)->assertForbidden();

        [, $viewer] = $this->api($s, ['inventory_reconcile.view']);
        $this->propose($s, $viewer)->assertForbidden(); // viewing is not managing
        $report = $this->getJson('/api/v1/app/inventory/reconciliation', $viewer)->assertOk();
        $this->assertSame(1, $report->json('summary.PROVABLE_UNDEDUCTED'));
        $this->assertNotContains($other['undeducted']->id, array_column($report->json('data'), 'installation_id'));
        $this->getJson('/api/v1/app/inventory/reconciliation?category=BOGUS', $viewer)->assertStatus(422);

        // A user scoped to another warehouse sees nothing of this one and cannot act on it.
        $elsewhere = $this->makeWarehouse($s['tenant'], $s['branch'], $s['workshop']);
        [, $scoped] = $this->api($s, ['inventory_reconcile.view', 'inventory_reconcile.manage'], ['WAREHOUSE' => $elsewhere->id]);
        $this->assertSame(0, $this->getJson('/api/v1/app/inventory/reconciliation', $scoped)->assertOk()->json('summary.PROVABLE_UNDEDUCTED'));
        $this->propose($s, $scoped)->assertNotFound();

        // Another company can not touch this adjustment.
        [, $maker] = $this->api($s, ['inventory_reconcile.manage']);
        $id = $this->propose($s, $maker)->assertCreated()->json('data.id');
        [, $foreign] = $this->api($other, ['inventory_reconcile.view', 'inventory_reconcile.manage', 'inventory_reconcile.approve']);
        $this->postJson(self::BASE."/{$id}/approve", [], $foreign)->assertNotFound();
        $this->postJson(self::BASE."/{$id}/apply", [], $foreign)->assertNotFound();
        $this->assertSame([], $this->getJson(self::BASE, $foreign)->assertOk()->json('data'));
        $this->assertSame(2.0, $this->onHand($other));
    }

    public function test_the_command_is_dry_run_by_default_and_only_files_proposals(): void
    {
        $s = $this->inconsistentFixture();
        [$maker] = $this->api($s, []);
        $before = $this->snapshot($s);

        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $s['tenant']->id])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $s['tenant']->id, '--propose' => true])->assertFailed();
        $this->assertSame($before, $this->snapshot($s));
        $this->assertSame(0, StockReconciliationAdjustment::query()->count());

        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $s['tenant']->id, '--propose' => true, '--user' => $maker->id, '--reason' => 'Batch review'])->assertSuccessful();
        $this->assertSame(1, StockReconciliationAdjustment::query()->where('status', 'PENDING_APPROVAL')->count());
        $this->assertSame($before, $this->snapshot($s), 'filing proposals never changes stock');
        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $s['tenant']->id, '--propose' => true, '--user' => $maker->id, '--reason' => 'again'])->assertSuccessful();
        $this->assertSame(1, StockReconciliationAdjustment::query()->count(), 're-running does not duplicate proposals');
    }
}
