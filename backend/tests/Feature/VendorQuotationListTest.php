<?php

namespace Tests\Feature;

use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Services\RfqService;
use Database\Seeders\DemoQuotationDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Vendor Quotations list shows the human-readable RFQ Number, loaded without N+1 queries. */
class VendorQuotationListTest extends TestCase
{
    private function quote($tenant, $warehouse, $product, string $vendorName): Rfq
    {
        $service = app(RfqService::class);
        $rfq = $service->create($warehouse, [], [['product_id' => $product->id, 'quantity' => 2]]);
        $vendor = $this->makePartner($tenant, ['name' => $vendorName]);
        $service->inviteVendors($rfq, [$vendor->id]);
        $service->submitQuotation($rfq->fresh(), $vendor, [], [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]], DemoQuotationDocument::make($vendorName), null);

        return $rfq->fresh();
    }

    public function test_list_shows_the_rfq_number_with_a_constant_number_of_queries(): void
    {
        Storage::fake('local');
        $tenant = $this->makeTenant(['code' => 'VQL-'.Str::random(4)]);
        $this->grantModule($tenant, 'PROCUREMENT');
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant);
        $first = $this->quote($tenant, $warehouse, $product, 'Vendor 1');
        [, $token] = $this->makeTenantUser($tenant, ['quotation.view']);
        $headers = $this->authHeaders($token);

        $row = $this->getJson('/api/v1/app/quotations', $headers)->assertOk()->json('data.0');
        $this->assertSame($first->rfq_number, $row['rfq']['rfq_number']);
        $this->assertMatchesRegularExpression('#^RFQ/\d{4}/\d{6}$#', $row['rfq']['rfq_number']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/app/quotations', $headers)->assertOk();
        $queriesForOne = count(DB::getQueryLog());
        foreach (range(2, 5) as $n) {
            $this->quote($tenant, $warehouse, $product, "Vendor {$n}");
        }
        DB::flushQueryLog();
        $rows = $this->getJson('/api/v1/app/quotations', $headers)->assertOk()->json('data');
        $this->assertCount(5, $rows);
        $this->assertSame($queriesForOne, count(DB::getQueryLog()), 'RFQ numbers are eager-loaded, not queried per row.');
        $this->assertTrue(collect($rows)->every(fn ($r) => str_starts_with($r['rfq']['rfq_number'], 'RFQ/')));
    }

    public function test_list_respects_tenant_and_warehouse_scope(): void
    {
        Storage::fake('local');
        $tenant = $this->makeTenant(['code' => 'VQS-'.Str::random(4)]);
        $this->grantModule($tenant, 'PROCUREMENT');
        $warehouseA = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $warehouseB = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant);
        $rfqA = $this->quote($tenant, $warehouseA, $product, 'Vendor A');
        $this->quote($tenant, $warehouseB, $product, 'Vendor B');

        [, $scoped] = $this->makeTenantUser($tenant, ['quotation.view'], ['WAREHOUSE' => $warehouseA->id]);
        $rows = $this->getJson('/api/v1/app/quotations', $this->authHeaders($scoped))->assertOk()->json('data');
        $this->assertSame([$rfqA->rfq_number], array_column(array_column($rows, 'rfq'), 'rfq_number'));

        $other = $this->makeTenant(['code' => 'VQX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, ['quotation.view']);
        $this->getJson('/api/v1/app/quotations', $this->authHeaders($foreign))->assertOk()->assertJsonCount(0, 'data');
    }
}
