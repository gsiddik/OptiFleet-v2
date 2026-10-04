<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Services\TireException;
use App\Domain\Tire\Services\TireService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Used stock lifecycle: every used, non-terminal tire is visible in Tire Detail → Used Stocks, but
 * only REUSE counts as reusable stock and only new stock / REUSE can be installed.
 */
class TireUsedStockLifecycleTest extends TestCase
{
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TUS-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 55 TUS']);
        [, $token] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.scrap']);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Used Lifecycle Tire']);

        return [$tenant, $vehicle, $product, $this->authHeaders($token)];
    }

    /** A tire that was installed and removed, then given the status. */
    private function usedTire($tenant, $vehicle, $product, array $headers, string $serial, string $status): Tire
    {
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FL', 'odometer' => 100], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'worn', 'disposition' => 'REUSE', 'odometer' => 200], $headers)->assertSuccessful();
        $tire->refresh()->update(['current_status' => $status]);

        return $tire->fresh();
    }

    private function inventory(array $headers, string $productId): array
    {
        return $this->getJson("/api/v1/app/tire-products/{$productId}", $headers)->assertOk()->json('data.inventory');
    }

    private function usedRows(array $headers, string $productId)
    {
        return collect($this->getJson("/api/v1/app/tire-products/{$productId}/inventory?category=USED", $headers)->assertOk()->json('data'))->keyBy('serial_number');
    }

    public function test_removed_reuse_and_retread_are_used_stock_but_only_reuse_is_reusable(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();

        $this->usedTire($tenant, $vehicle, $product, $headers, 'U-REMOVED', 'REMOVED');
        $this->assertSame(['used_qty' => 1, 'reusable_qty' => 0], array_intersect_key($this->inventory($headers, $product->id), array_flip(['used_qty', 'reusable_qty'])));
        // REMOVED is also listed in Used Tire Management → Removed (REMOVED + HOLD).
        $this->assertSame(['U-REMOVED'], array_column($this->getJson('/api/v1/app/tires?current_status=REMOVED,HOLD', $headers)->json('data'), 'serial_number'));

        $this->usedTire($tenant, $vehicle, $product, $headers, 'U-REUSE', 'REUSE');
        $this->assertSame(['used_qty' => 2, 'reusable_qty' => 1], array_intersect_key($this->inventory($headers, $product->id), array_flip(['used_qty', 'reusable_qty'])));

        $this->usedTire($tenant, $vehicle, $product, $headers, 'U-RETREAD', 'RETREAD');
        $this->assertSame(['used_qty' => 3, 'reusable_qty' => 1], array_intersect_key($this->inventory($headers, $product->id), array_flip(['used_qty', 'reusable_qty'])), 'RETREAD is visible but not reusable');

        foreach (['REPAIR', 'HOLD'] as $status) {
            $this->usedTire($tenant, $vehicle, $product, $headers, "U-{$status}", $status);
        }
        $this->usedTire($tenant, $vehicle, $product, $headers, 'U-SCRAP', 'SCRAPPED');
        $rows = $this->usedRows($headers, $product->id);
        $this->assertEqualsCanonicalizing(['U-REMOVED', 'U-REUSE', 'U-RETREAD', 'U-REPAIR', 'U-HOLD'], $rows->keys()->all(), 'SCRAP is terminal: kept in history, not stock');
        $this->assertSame('REMOVED', $rows['U-REMOVED']['current_status']);
        $this->assertSame(1, $this->inventory($headers, $product->id)['reusable_qty']);
        // The Tire List shows the same counts.
        $listed = collect($this->getJson('/api/v1/app/tire-products', $headers)->json('data'))->firstWhere('id', $product->id);
        $this->assertSame([5, 1], [$listed['used_qty'], $listed['reusable_qty']]);
    }

    public function test_only_new_stock_and_reuse_can_be_installed(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        foreach (['REMOVED', 'HOLD', 'REPAIR', 'RETREAD', 'SCRAPPED'] as $status) {
            $tire = $this->usedTire($tenant, $vehicle, $product, $headers, "NA-{$status}", $status);
            $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FR', 'odometer' => 300], $headers)->assertStatus(422);
        }
        $reuse = $this->usedTire($tenant, $vehicle, $product, $headers, 'OK-REUSE', 'REUSE');
        $this->postJson("/api/v1/app/tires/{$reuse->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FR', 'odometer' => 300], $headers)->assertStatus(201);
        $this->assertSame('INSTALLED', $reuse->fresh()->current_status);
    }

    public function test_a_removed_tire_is_scrapped_through_its_inspection_not_directly(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        $removed = $this->usedTire($tenant, $vehicle, $product, $headers, 'SC-REMOVED', 'REMOVED');
        $this->postJson("/api/v1/app/tires/{$removed->id}/scrap", ['reason' => 'x'], $headers)->assertStatus(422);
        $this->assertSame('REMOVED', $removed->fresh()->current_status);

        $hold = $this->usedTire($tenant, $vehicle, $product, $headers, 'SC-HOLD', 'HOLD');
        $this->postJson("/api/v1/app/tires/{$hold->id}/scrap", ['reason' => 'specialist rejected'], $headers)->assertOk();
        $this->assertSame('SCRAPPED', $hold->fresh()->current_status);
    }

    public function test_repair_or_retread_approval_returns_the_tire_for_re_inspection(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        $tire = $this->usedTire($tenant, $vehicle, $product, $headers, 'CY-1', 'RETREAD');
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP', 'status' => 'ACTIVE']);
        $service = app(TireService::class);
        $cycle = $service->retread($tire, $partner->id, null, null, null);
        $cycle = $service->finalInspectRetread($service->receiveRetread($cycle, (string) Str::uuid()), 'SAFE', null, null);
        $service->approveRetread($cycle, 'RETURN_TO_SERVICE', 'Retread passed', (string) Str::uuid());
        // Not straight back to stock: REMOVED, awaiting its Used Tire Management inspection.
        $this->assertSame('REMOVED', $tire->fresh()->current_status);

        $this->expectException(TireException::class);
        $service->install($tire->fresh(), $vehicle, 'FR', 300, null, null);
    }
}
