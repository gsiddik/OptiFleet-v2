<?php

namespace Database\Seeders;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Tire\Services\RimRegistrationService;
use App\Support\TenantContext;

/**
 * Functional-test Rim data: New Stock serial numbers of the serial-tracked rim product (TEST-RIM-002)
 * in the functional warehouse, registered through RimRegistrationService. The functional vehicles have
 * no Wheels Configuration, so Installed rims are demo data (RimDemoSeeder) and test fixtures.
 * Idempotent: existing serials are skipped.
 */
class FunctionalTestRimSeeder
{
    public const SERIALS = ['TEST-RIM-SN-0001', 'TEST-RIM-SN-0002', 'TEST-RIM-SN-0003'];

    public function run(Tenant $tenant, object $products): void
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $product = $products->bySku['TEST-RIM-002'] ?? null;
        $warehouse = Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN-WH1')->first();
        if (! $product || ! $warehouse) {
            return;
        }
        foreach (self::SERIALS as $serial) {
            if (ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('serial_number', $serial)->exists()) {
                continue;
            }
            app(RimRegistrationService::class)->register($tenant->id, $product, ['serial_number' => $serial, 'warehouse_id' => $warehouse->id], null);
        }
    }
}
