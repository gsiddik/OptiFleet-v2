<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Used Tire Management → Removed → Inspect & return to stock: a removed tire is listed in Used
 * Stocks with status REMOVED, is not reusable until inspected, and the inspection either returns
 * it to stock as Used (PASS) or sends it to retread / repair / scrap (FAIL).
 */
class TireRemovedInspectionTest extends TestCase
{
    private const PERMISSIONS = ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.inspect'];

    private function scenario(array $permissions = self::PERMISSIONS): array
    {
        $tenant = $this->makeTenant(['code' => 'TRI-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 77 TRI', 'current_odometer' => 1000]);
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        [, $adminToken] = $this->makeTenantUser($tenant, self::PERMISSIONS);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'GT Radial']);

        return compact('tenant', 'warehouse', 'vehicle', 'product') + ['headers' => $this->authHeaders($token), 'admin' => $this->authHeaders($adminToken)];
    }

    /** A tire installed and then removed (as a Replacement does): status REMOVED. */
    private function removedTire(array $s, string $serial): Tire
    {
        $tire = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FL', 'odometer' => 1000], $s['admin'])->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'replaced', 'disposition' => 'REUSE', 'odometer' => 2000], $s['admin'])->assertSuccessful();
        $this->assertSame('REMOVED', $tire->fresh()->current_status);

        return $tire->fresh();
    }

    private function inspect(array $s, Tire $tire, array $body)
    {
        return $this->postJson("/api/v1/app/tires/{$tire->id}/inspect-removed", $body, $s['headers']);
    }

    private function candidates(array $s)
    {
        return collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['admin'])->assertOk()->json('data'));
    }

    public function test_removed_tire_is_listed_in_used_stocks_and_is_not_reusable_before_inspection(): void
    {
        $s = $this->scenario();
        $tire = $this->removedTire($s, 'RMV-001');

        $used = collect($this->getJson("/api/v1/app/tire-products/{$s['product']->id}/inventory?category=USED", $s['headers'])->assertOk()->json('data'));
        $this->assertSame('REMOVED', $used->firstWhere('serial_number', 'RMV-001')['current_status']);
        $this->assertSame(1, $this->getJson('/api/v1/app/tires?current_status=REMOVED', $s['headers'])->assertOk()->json('meta.total'));
        $this->assertFalse($this->candidates($s)->pluck('serial_number')->contains($tire->serial_number));
    }

    public function test_pass_returns_the_tire_to_stock_as_used_and_records_the_inspection(): void
    {
        $s = $this->scenario();
        $tire = $this->removedTire($s, 'RMV-002');

        $this->inspect($s, $tire, ['tread_depth_mm' => '6.5', 'condition' => 'even wear', 'result' => 'PASS', 'warehouse_id' => $s['warehouse']->id])
            ->assertOk()->assertJsonPath('data.current_status', 'REUSE')->assertJsonPath('data.current_warehouse_id', $s['warehouse']->id);

        $inspection = TireInspection::query()->where('tire_id', $tire->id)->latest('inspected_at')->first();
        $this->assertSame(['6.50', 'even wear'], [(string) $inspection->tread_depth_mm, $inspection->condition]);
        $this->assertStringContainsString('PASS', $inspection->recommendation);
        // Back in stock as Used: still under Used Stocks (it was installed before) and offered as Reuse.
        $used = collect($this->getJson("/api/v1/app/tire-products/{$s['product']->id}/inventory?category=USED", $s['headers'])->json('data'));
        $this->assertSame('REUSE', $used->firstWhere('serial_number', 'RMV-002')['current_status']);
        $this->assertSame('REUSE', $this->candidates($s)->firstWhere('serial_number', 'RMV-002')['source']);

        // Only a REMOVED tire can be inspected here.
        $this->inspect($s, $tire, ['tread_depth_mm' => '6', 'result' => 'PASS', 'warehouse_id' => $s['warehouse']->id])->assertStatus(422);
    }

    public function test_fail_sends_the_tire_to_retread_repair_or_scrap(): void
    {
        $s = $this->scenario();
        foreach (['RETREAD' => 'RETREAD', 'REPAIR' => 'REPAIR', 'SCRAP' => 'SCRAPPED'] as $disposition => $status) {
            $tire = $this->removedTire($s, "RMV-F-{$disposition}");
            $this->inspect($s, $tire, ['tread_depth_mm' => '2.1', 'result' => 'FAIL', 'fail_disposition' => $disposition, 'notes' => 'sidewall cut'])
                ->assertOk()->assertJsonPath('data.current_status', $status)->assertJsonPath('data.current_warehouse_id', null);
            $this->assertStringContainsString('FAIL', TireInspection::query()->where('tire_id', $tire->id)->latest('inspected_at')->value('recommendation'));
        }
    }

    public function test_validation_scope_and_permission(): void
    {
        $s = $this->scenario();
        $tire = $this->removedTire($s, 'RMV-003');

        $this->inspect($s, $tire, ['result' => 'PASS', 'warehouse_id' => $s['warehouse']->id])->assertStatus(422)->assertJsonValidationErrors('tread_depth_mm');
        $this->inspect($s, $tire, ['tread_depth_mm' => '5', 'result' => 'PASS'])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->inspect($s, $tire, ['tread_depth_mm' => '5', 'result' => 'FAIL'])->assertStatus(422)->assertJsonValidationErrors('fail_disposition');
        $this->inspect($s, $tire, ['tread_depth_mm' => '-1', 'result' => 'MAYBE'])->assertStatus(422)->assertJsonValidationErrors(['tread_depth_mm', 'result']);

        // Another tenant's warehouse is rejected; another tenant's tire is not found.
        $other = $this->scenario();
        $this->inspect($s, $tire, ['tread_depth_mm' => '5', 'result' => 'PASS', 'warehouse_id' => $other['warehouse']->id])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->inspect($other, $tire, ['tread_depth_mm' => '5', 'result' => 'PASS', 'warehouse_id' => $other['warehouse']->id])->assertNotFound();

        // tire.inspect is required.
        $viewer = $this->scenario(['tire.view']);
        $this->inspect($viewer, $this->removedTire($viewer, 'RMV-004'), ['tread_depth_mm' => '5', 'result' => 'PASS', 'warehouse_id' => $viewer['warehouse']->id])->assertForbidden();
        $this->assertSame('REMOVED', $tire->fresh()->current_status);
    }
}
