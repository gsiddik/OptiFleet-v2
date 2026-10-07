<?php

namespace Tests\Feature\StockIntegrity;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\Inventory\Models\InstallationStockExit;
use App\Domain\Inventory\Services\InventoryException;
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
        $this->assertSame('PROVABLE_UNDEDUCTED', $byId[$s['undeducted']->id]['category']);
        $this->assertSame('COVERED_BY_WO_ISSUE', $byId[$s['covered']->id]['category']);
        $this->assertSame('AMBIGUOUS_NO_RECEIPT', $byId[ComponentInstallation::query()->where('component_asset_id', $noReceipt->id)->value('id')]['category']);
        $this->assertSame('NOT_WAREHOUSE_ORIGIN', $byId[TireInstallation::query()->where('tire_id', $registered->id)->value('id')]['category']);
        $this->assertSame('AMBIGUOUS_TIRE', $byId[TireInstallation::query()->where('tire_id', $standard->id)->value('id')]['category']);
        $this->assertSame(['PROVABLE_UNDEDUCTED' => 1, 'COVERED_BY_WO_ISSUE' => 1, 'AMBIGUOUS_NO_RECEIPT' => 1, 'AMBIGUOUS_TIRE' => 1, 'NOT_WAREHOUSE_ORIGIN' => 1], collect($tenant['summary'])->except('installations')->all());

        // The balance corroborates: ledger 2 vs 1 unit registered in stock = 1 difference, explained by the one provable case.
        $balance = collect($tenant['balance'])->firstWhere('product_id', $s['product']->id);
        $this->assertSame(['2.0000', 1, '1.0000', 1], [$balance['ledger_on_hand'], $balance['registered_in_stock_units'], $balance['difference'], $balance['explained_by_provable_undeducted']]);
    }

    public function test_apply_requires_the_reviewed_plan_corrects_only_provable_cases_and_cannot_run_twice(): void
    {
        $s = $this->inconsistentFixture();
        $service = app(SerializedStockReconciliationService::class);
        $hash = $service->plan($s['tenant']->id)['plan_hash'];

        try {
            $service->apply('0'.substr($hash, 1), $s['tenant']->id);
            $this->fail('an unreviewed plan must be rejected');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('dry-run', $e->getMessage());
        }
        $this->assertSame(2.0, $this->onHand($s));

        $result = $service->apply($hash, $s['tenant']->id);

        $this->assertSame(['applied' => 1, 'skipped' => [], 'already_done' => 0], $result);
        $this->assertSame(1.0, $this->onHand($s), 'ledger now equals the units registered in stock');
        $exit = InstallationStockExit::query()->where('installation_id', $s['undeducted']->id)->firstOrFail();
        $this->assertSame(['DIRECT_ISSUE', 'RECONCILED'], [$exit->source, $exit->reason]);
        $movement = DB::table('stock_movements')->where('id', $exit->stock_movement_id)->first();
        $this->assertSame('ISSUE', $movement->movement_type);
        $this->assertTrue(\Carbon\Carbon::parse($movement->occurred_at)->greaterThan(now()->subMinute()), 'booked now, never back-dated');
        $this->assertStringContainsString('2026-08-10', $movement->reason);
        $this->assertSame(0, InstallationStockExit::query()->where('installation_id', $s['covered']->id)->count(), 'the covered case is left alone');

        // Not applicable twice: the old plan is stale, the new plan has nothing to correct.
        try {
            $service->apply($hash, $s['tenant']->id);
            $this->fail('a plan cannot be applied twice');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('dry-run', $e->getMessage());
        }
        $again = $service->plan($s['tenant']->id);
        $this->assertSame(['applied' => 0, 'skipped' => [], 'already_done' => 0], $service->apply($again['plan_hash'], $s['tenant']->id));
        $this->assertSame(1.0, $this->onHand($s));
        $this->assertSame(1, DB::table('stock_movements')->where('reference_id', $s['undeducted']->id)->count());
        $this->assertSame([], collect($again['tenants'][0]['balance'])->where('product_id', $s['product']->id)->all(), 'no difference left to explain');
    }

    public function test_an_uncoverable_unit_is_skipped_never_forced(): void
    {
        $s = $this->inconsistentFixture();
        DB::table('warehouse_stocks')->where('warehouse_id', $s['warehouse']->id)->where('product_id', $s['product']->id)->update(['quantity_on_hand' => 0]);
        $service = app(SerializedStockReconciliationService::class);

        $result = $service->apply($service->plan($s['tenant']->id)['plan_hash'], $s['tenant']->id);

        $this->assertSame(0, $result['applied']);
        $this->assertSame([['installation_id' => $s['undeducted']->id, 'reason' => 'INSUFFICIENT_STOCK']], $result['skipped']);
        $this->assertSame(0.0, $this->onHand($s));
        $this->assertSame(0, InstallationStockExit::query()->count());
    }

    public function test_tenants_are_isolated_and_the_command_is_dry_run_by_default(): void
    {
        $mine = $this->inconsistentFixture('MIN');
        $other = $this->inconsistentFixture('OTH');
        $service = app(SerializedStockReconciliationService::class);

        $plan = $service->plan($mine['tenant']->id);
        $this->assertSame([$mine['tenant']->id], array_column($plan['tenants'], 'tenant_id'));
        $service->apply($plan['plan_hash'], $mine['tenant']->id);
        $this->assertSame(1.0, $this->onHand($mine));
        $this->assertSame(2.0, $this->onHand($other), 'the other company is untouched');

        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $other['tenant']->id])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame(2.0, $this->onHand($other));
        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $other['tenant']->id, '--apply' => true])->assertFailed();
        $this->assertSame(2.0, $this->onHand($other));
        $hash = $service->plan($other['tenant']->id)['plan_hash'];
        $this->artisan('inventory:reconcile-serialized-installations', ['--tenant' => $other['tenant']->id, '--apply' => true, '--plan-hash' => $hash])->assertSuccessful();
        $this->assertSame(1.0, $this->onHand($other));
    }
}
