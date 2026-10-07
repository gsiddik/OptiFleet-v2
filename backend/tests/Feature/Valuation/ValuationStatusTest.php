<?php

namespace Tests\Feature\Valuation;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Support\ValuationStatus;
use App\Domain\Procurement\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Valuation status is a fact about the SOURCE of a value, never derived from the unit cost alone. */
class ValuationStatusTest extends TestCase
{
    private array $s;

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'VAL-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'P '.Str::random(4), 'product_type' => 'SPARE_PART']);

        return $this->s = compact('tenant', 'branch', 'workshop', 'warehouse', 'product');
    }

    private function balance(): object
    {
        return DB::table('warehouse_stocks')->where('warehouse_id', $this->s['warehouse']->id)->where('product_id', $this->s['product']->id)->first();
    }

    private function receive(float $qty, float $cost, string $type = 'RECEIPT', bool $gr = true): void
    {
        app(InventoryService::class)->receive($this->s['warehouse'], $this->s['product'], $qty, $cost, $type, $gr ? GoodsReceipt::class : null, $gr ? (string) Str::uuid() : null, null);
    }

    public function test_goods_receipt_with_price_is_verified_and_keeps_the_purchase_price_separate(): void
    {
        $this->scenario();
        $this->receive(5, 1000);
        $b = $this->balance();
        $this->assertSame(ValuationStatus::VERIFIED, $b->valuation_status);
        $this->assertSame('PO_UNIT_PRICE', $b->valuation_basis);
        $m = DB::table('stock_movements')->where('warehouse_id', $this->s['warehouse']->id)->first();
        $this->assertSame('1000.0000', $m->purchase_unit_price);
        $this->assertSame(ValuationStatus::VERIFIED, $m->valuation_status);
    }

    public function test_free_goods_receipt_is_unverified_not_verified_zero(): void
    {
        $this->scenario();
        $this->receive(5, 0);
        $b = $this->balance();
        $this->assertSame(ValuationStatus::UNVERIFIED, $b->valuation_status);
        $this->assertNull($b->valuation_basis);
        $m = DB::table('stock_movements')->where('warehouse_id', $this->s['warehouse']->id)->first();
        $this->assertSame('0.0000', $m->purchase_unit_price, 'purchase price 0 is recorded as a fact');
        $this->assertSame('0.0000', $this->balance()->average_unit_cost);
    }

    public function test_opening_balance_and_non_receipt_sources_are_unverified(): void
    {
        $this->scenario();
        $this->receive(2, 500, 'OPENING', false);
        $this->assertSame(ValuationStatus::UNVERIFIED, $this->balance()->valuation_status);
        $this->assertSame('OPENING_BALANCE_UNREVIEWED', $this->balance()->valuation_basis);
    }

    public function test_mixed_sources_are_never_presented_as_verified(): void
    {
        $this->scenario();
        $this->receive(5, 1000);
        $this->receive(5, 0);
        $this->assertSame(ValuationStatus::MIXED, $this->balance()->valuation_status);
        $this->assertSame('MIXED_SOURCES', $this->balance()->valuation_basis);
        $this->receive(5, 1200); // more verified stock does not clear the mix
        $this->assertSame(ValuationStatus::MIXED, $this->balance()->valuation_status);
    }

    public function test_combine_rules(): void
    {
        $this->assertSame('VERIFIED', ValuationStatus::combine(null, 0, 'VERIFIED'));
        $this->assertSame('UNVERIFIED', ValuationStatus::combine('VERIFIED', 0, 'UNVERIFIED'), 'an empty balance takes the incoming status');
        $this->assertSame('MIXED', ValuationStatus::combine('VERIFIED', 3, 'UNVERIFIED'));
        $this->assertSame('UNVERIFIED', ValuationStatus::combine('UNVERIFIED', 3, 'UNVERIFIED'));
    }

    public function test_backfill_classification_is_deterministic_and_never_changes_costs(): void
    {
        $this->scenario();
        $this->receive(4, 0);
        DB::table('warehouse_stocks')->update(['valuation_status' => null, 'valuation_basis' => null]);
        DB::table('stock_movements')->update(['valuation_status' => null, 'valuation_basis' => null, 'purchase_unit_price' => null]);
        $before = DB::table('warehouse_stocks')->pluck('average_unit_cost', 'id')->all();
        $migration = require base_path('database/migrations/2026_10_19_000002_backfill_stock_valuation_status.php');
        $migration->up();
        $migration->up(); // idempotent
        $b = $this->balance();
        $this->assertSame(ValuationStatus::UNVERIFIED, $b->valuation_status, 'cost 0 is never classified as verified');
        $this->assertSame('LEGACY_ZERO_COST', $b->valuation_basis);
        $this->assertSame($before, DB::table('warehouse_stocks')->pluck('average_unit_cost', 'id')->all());
    }

    private function reviewer(array $perms, ?array $scopes = null): array
    {
        return $this->makeTenantUser($this->s['tenant'], $perms, $scopes);
    }

    public function test_review_requires_permission_evidence_and_never_edits_cost(): void
    {
        $this->scenario();
        $this->receive(5, 0);
        $stockId = $this->balance()->id;
        [, $viewer] = $this->reviewer(['inventory_valuation.view']);
        $url = "/api/v1/app/inventory/valuation/{$stockId}/review";
        $payload = ['status' => 'VERIFIED_ZERO', 'basis' => 'FREE_OF_CHARGE_DOCUMENTED', 'reason' => 'Donation received', 'evidence_reference' => 'DON-001'];

        $this->postJson($url, $payload, $this->authHeaders($viewer))->assertForbidden();

        [$user, $verifier] = $this->reviewer(['inventory_valuation.view', 'inventory_valuation.verify']);
        $h = $this->authHeaders($verifier);
        $this->postJson($url, array_diff_key($payload, ['evidence_reference' => 1]), $h)->assertStatus(422);
        $this->postJson($url, ['status' => 'VERIFIED', 'basis' => 'SUPPLIER_DOCUMENT', 'reason' => 'x', 'evidence_reference' => 'INV-1'], $h)->assertStatus(422); // cost 0 cannot be VERIFIED
        $this->postJson($url, ['status' => 'VERIFIED_ZERO', 'basis' => 'SUPPLIER_DOCUMENT', 'reason' => 'x', 'evidence_reference' => 'INV-1'], $h)->assertStatus(422); // wrong basis
        $this->assertSame(ValuationStatus::UNVERIFIED, $this->balance()->valuation_status);

        $this->postJson($url, $payload, $h)->assertCreated();
        $b = $this->balance();
        $this->assertSame(ValuationStatus::VERIFIED_ZERO, $b->valuation_status);
        $this->assertSame('0.0000', $b->average_unit_cost);
        $log = DB::table('stock_valuation_reviews')->where('warehouse_stock_id', $stockId)->first();
        $this->assertSame($user->id, $log->reviewed_by);
        $this->assertSame('UNVERIFIED', $log->from_status);
        $this->assertSame('DON-001', $log->evidence_reference);
        $this->postJson($url, $payload, $h)->assertStatus(422); // already in this status
    }

    public function test_verifying_a_mixed_balance_needs_explicit_acknowledgement(): void
    {
        $this->scenario();
        $this->receive(5, 1000);
        $this->receive(5, 0);
        $stockId = $this->balance()->id;
        [, $t] = $this->reviewer(['inventory_valuation.verify']);
        $h = $this->authHeaders($t);
        $url = "/api/v1/app/inventory/valuation/{$stockId}/review";
        $p = ['status' => 'VERIFIED', 'basis' => 'COSTING_REVIEW', 'reason' => 'Reviewed', 'evidence_reference' => 'REV-1'];
        $this->postJson($url, $p, $h)->assertStatus(422);
        $this->postJson($url, $p + ['acknowledge_mixed_sources' => true], $h)->assertCreated();
        $this->assertSame(ValuationStatus::VERIFIED, $this->balance()->valuation_status);
    }

    public function test_listing_is_tenant_and_warehouse_scoped_and_filterable(): void
    {
        $this->scenario();
        $this->receive(5, 1000);
        $other = $this->makeWarehouse($this->s['tenant'], $this->s['branch'], $this->s['workshop']);
        app(InventoryService::class)->receive($other, $this->s['product'], 2, 0, 'RECEIPT', GoodsReceipt::class, (string) Str::uuid(), null);
        [, $all] = $this->reviewer(['inventory_valuation.view']);
        [, $scoped] = $this->reviewer(['inventory_valuation.view'], ['WAREHOUSE' => $other->id]);

        $this->getJson('/api/v1/app/inventory/valuation', $this->authHeaders($all))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/app/inventory/valuation?status=UNVERIFIED', $this->authHeaders($all))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/app/inventory/valuation?status=BOGUS', $this->authHeaders($all))->assertStatus(422);
        $rows = $this->getJson('/api/v1/app/inventory/valuation', $this->authHeaders($scoped))->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($other->id, $rows[0]['warehouse']['id']);

        $mine = $this->balance()->id;
        $this->getJson("/api/v1/app/inventory/valuation/{$mine}", $this->authHeaders($scoped))->assertNotFound();

        $noModule = $this->makeTenant(['code' => 'NOM-'.Str::random(4)]);
        [, $nt] = $this->makeTenantUser($noModule, ['inventory_valuation.view']);
        $this->getJson('/api/v1/app/inventory/valuation', $this->authHeaders($nt))->assertForbidden(); // no INVENTORY entitlement

        $foreign = $this->makeTenant(['code' => 'FOR-'.Str::random(4)]);
        $this->grantModule($foreign, 'INVENTORY');
        [, $ft] = $this->makeTenantUser($foreign, ['inventory_valuation.view']);
        $this->getJson("/api/v1/app/inventory/valuation/{$mine}", $this->authHeaders($ft))->assertNotFound();
        $this->getJson('/api/v1/app/inventory/valuation', $this->authHeaders($ft))->assertOk()->assertJsonCount(0, 'data');
    }
}
