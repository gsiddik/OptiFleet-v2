<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\WorkOrder\Models\SparePartSale;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Used Tire Management → Scrap → Recently Scrapped → Sell (row or bulk) → Sell Sparepart: one sale
 * line per physical tire, only SCRAPPED tires, serial identity kept, approval marks the tire SOLD.
 */
class ScrappedTireSaleTest extends TestCase
{
    private const PERMISSIONS = ['tire.view', 'tire.scrap', 'sparepart_sale.view', 'sparepart_sale.create', 'sparepart_sale.approve'];

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'STS-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Scrap Tire']);
        $make = fn (string $serial, string $status) => Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => $status]);
        $tires = ['a' => $make('SCR-A', 'SCRAPPED'), 'b' => $make('SCR-B', 'SCRAPPED'), 'c' => $make('SCR-C', 'SCRAPPED'), 'hold' => $make('HLD-1', 'HOLD')];
        [, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);

        return ['tenant' => $tenant, 'tires' => $tires, 'headers' => $this->authHeaders($token)];
    }

    private function sell(array $s, array $tireIds, ?array $headers = null)
    {
        return $this->postJson('/api/v1/app/sparepart-sales/scrapped-tires', [
            'tire_ids' => $tireIds, 'buyer_type' => 'EXTERNAL', 'buyer_name' => 'CV Daur Ulang', 'unit_price' => '125000.50',
        ], $headers ?? $s['headers']);
    }

    public function test_recently_scrapped_lists_scrapped_tires_with_their_active_sale(): void
    {
        $s = $this->scenario();
        $rows = collect($this->getJson('/api/v1/app/tires-scrapped', $s['headers'])->assertOk()->json('data'))->keyBy('serial_number');
        $this->assertEqualsCanonicalizing(['SCR-A', 'SCR-B', 'SCR-C'], $rows->keys()->all());
        $this->assertNull($rows['SCR-A']['sale_id']);
        // The Sell Sparepart form loads exactly the selected tires.
        $ids = $rows['SCR-A']['id'].','.$rows['SCR-B']['id'];
        $picked = collect($this->getJson('/api/v1/app/tires-scrapped?ids='.$ids, $s['headers'])->assertOk()->json('data'))->pluck('serial_number');
        $this->assertEqualsCanonicalizing(['SCR-A', 'SCR-B'], $picked->all());

        $this->sell($s, [$s['tires']['a']->id])->assertStatus(201);
        $rows = collect($this->getJson('/api/v1/app/tires-scrapped', $s['headers'])->json('data'))->keyBy('serial_number');
        $this->assertSame('DRAFT', $rows['SCR-A']['sale_status']);
    }

    public function test_bulk_and_row_sell_create_one_sale_per_tire_keeping_the_serial(): void
    {
        $s = $this->scenario();
        // Bulk: two selected tires.
        $sales = $this->sell($s, [$s['tires']['a']->id, $s['tires']['b']->id])->assertStatus(201)->json('data');
        $this->assertCount(2, $sales);
        $this->assertEqualsCanonicalizing(['SCR-A', 'SCR-B'], array_column($sales, 'tire_serial_number'));
        foreach ($sales as $sale) {
            $this->assertSame(['SCRAPPED_TIRE', 'SCRAPPED', 'SCRAP_MATERIAL', '1.0000', '125000.5000', '125000.5000', 'DRAFT'], [
                $sale['source_type'], $sale['tire_status'], $sale['sale_type'], $sale['quantity'], $sale['unit_price'], $sale['total_amount'], $sale['status'],
            ]);
            $this->assertNull($sale['warehouse_id']);
        }
        // Row: one tire.
        $this->sell($s, [$s['tires']['c']->id])->assertStatus(201)->assertJsonCount(1, 'data');
        // A tire already in an active sale cannot be sold twice.
        $this->sell($s, [$s['tires']['a']->id])->assertStatus(422);
        $this->assertSame(3, SparePartSale::query()->where('source_type', 'SCRAPPED_TIRE')->count());
    }

    public function test_only_scrapped_tires_of_the_tenant_and_with_permission_can_be_sold(): void
    {
        $s = $this->scenario();
        $this->sell($s, [$s['tires']['hold']->id])->assertStatus(422);
        $this->sell($s, [$s['tires']['a']->id, $s['tires']['hold']->id])->assertStatus(422);
        $this->assertSame(0, SparePartSale::query()->count(), 'all-or-nothing');
        $this->postJson('/api/v1/app/sparepart-sales/scrapped-tires', ['tire_ids' => [$s['tires']['a']->id], 'buyer_type' => 'EXTERNAL', 'buyer_name' => 'X', 'unit_price' => '1', 'sale_type' => 'OPERATIONAL_REUSE'], $s['headers'])->assertStatus(422);
        $this->postJson('/api/v1/app/sparepart-sales/scrapped-tires', ['tire_ids' => [], 'buyer_type' => 'EXTERNAL', 'buyer_name' => 'X', 'unit_price' => '1'], $s['headers'])->assertStatus(422);

        [, $viewerToken] = $this->makeTenantUser($s['tenant'], ['tire.view', 'sparepart_sale.view']);
        $this->sell($s, [$s['tires']['a']->id], $this->authHeaders($viewerToken))->assertForbidden();

        $other = $this->makeTenant(['code' => 'STX-'.Str::random(4)]);
        $this->grantModule($other, 'INVENTORY');
        [, $otherToken] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->sell($s, [$s['tires']['a']->id], $this->authHeaders($otherToken))->assertStatus(403);
        $this->assertSame('SCRAPPED', $s['tires']['a']->fresh()->current_status);
    }

    public function test_approved_tire_sale_marks_the_tire_sold_and_a_rejected_one_frees_it(): void
    {
        $s = $this->scenario();
        [$approved, $rejected] = $this->sell($s, [$s['tires']['a']->id, $s['tires']['b']->id])->json('data');
        [, $approverToken] = $this->makeTenantUser($s['tenant'], ['sparepart_sale.view', 'sparepart_sale.approve']);
        foreach ([$approved, $rejected] as $sale) {
            $this->postJson("/api/v1/app/sparepart-sales/{$sale['id']}/submit", [], $s['headers'])->assertOk();
        }
        $this->postJson("/api/v1/app/sparepart-sales/{$approved['id']}/decide", ['decision' => 'APPROVE'], $this->authHeaders($approverToken))->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson("/api/v1/app/sparepart-sales/{$rejected['id']}/decide", ['decision' => 'REJECT', 'note' => 'Price too low'], $this->authHeaders($approverToken))->assertOk();

        $sold = Tire::query()->where('serial_number', $approved['tire_serial_number'])->firstOrFail();
        $this->assertSame('SOLD', $sold->current_status);
        $free = Tire::query()->where('serial_number', $rejected['tire_serial_number'])->firstOrFail();
        $this->assertSame('SCRAPPED', $free->current_status);
        $this->sell($s, [$free->id])->assertStatus(201);
        $this->assertFalse(collect($this->getJson('/api/v1/app/tires-scrapped', $s['headers'])->json('data'))->pluck('serial_number')->contains($sold->serial_number));
    }
}
