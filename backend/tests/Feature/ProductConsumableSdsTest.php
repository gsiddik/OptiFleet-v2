<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal - Products": Consumable's "Safety Data
 * Sheet | O | File Upload" — the sds_file_path/sds_original_filename
 * columns already existed on ProductConsumableSpec but nothing ever
 * populated them (no upload service, controller, route, or frontend
 * field existed anywhere in the repository before this batch).
 */
class ProductConsumableSdsTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'SDS-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update', 'product.delete', 'warehouse.view', 'warehouse.update']);
        $bin = $this->makeWarehouseBin($tenant);

        $product = $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Engine Oil 15W-40',
            'product_category_id' => $this->makeProductCategory()->id,
            'product_type' => 'CONSUMABLE', 'track_batch' => false,
            'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $bin->id,
            'spec' => ['track_expiry' => false, 'is_hazardous' => false],
        ], $this->authHeaders($token))->json('data.id');

        return [$tenant, $token, Product::query()->findOrFail($product)];
    }

    public function test_sds_can_be_uploaded_and_downloaded(): void
    {
        [, $token, $product] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $file = UploadedFile::fake()->create('msds-engine-oil.pdf', 500, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $file], $headers)->assertOk();

        $product->refresh();
        $this->assertNotNull($product->consumableSpec->sds_file_path);
        $this->assertSame('msds-engine-oil.pdf', $product->consumableSpec->sds_original_filename);
        Storage::disk('local')->assertExists($product->consumableSpec->sds_file_path);

        $this->getJson("/api/v1/app/products/{$product->id}/sds", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_sds_re_upload_replaces_and_deletes_previous_file(): void
    {
        [, $token, $product] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $first = UploadedFile::fake()->create('msds-v1.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $first], $headers)->assertOk();
        $firstPath = $product->refresh()->consumableSpec->sds_file_path;

        $second = UploadedFile::fake()->create('msds-v2.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $second], $headers)->assertOk();

        $product->refresh();
        $this->assertNotSame($firstPath, $product->consumableSpec->sds_file_path);
        $this->assertSame('msds-v2.pdf', $product->consumableSpec->sds_original_filename);
        Storage::disk('local')->assertMissing($firstPath);
    }

    public function test_sds_upload_rejects_disallowed_mime_type(): void
    {
        [, $token, $product] = $this->setUpTenant();

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $file], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_sds_upload_rejected_for_non_consumable_product_type(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $bin = $this->makeWarehouseBin($tenant);
        $productId = $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Brake Pad',
            'product_category_id' => $this->makeProductCategory(['item_type' => 'SPARE_PART'])->id,
            'product_type' => 'SPARE_PART', 'uom_id' => $this->makeUom()->id, 'default_storage_bin_id' => $bin->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => [
                'part_number' => 'PN-1', 'part_type' => 'GENUINE',
                'compatibilities' => [$this->vehicleFit()],
            ],
        ], $this->authHeaders($token))->json('data.id');

        $file = UploadedFile::fake()->create('msds.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$productId}/sds", ['file' => $file], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_sds_upload_requires_permission(): void
    {
        [$tenant, , $product] = $this->setUpTenant();
        [, $noPermToken] = $this->makeTenantUser($tenant, ['product.view']);

        $file = UploadedFile::fake()->create('msds.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $file], $this->authHeaders($noPermToken))
            ->assertStatus(403);
    }

    public function test_sds_can_be_deleted(): void
    {
        [, $token, $product] = $this->setUpTenant();
        $headers = $this->authHeaders($token);
        $file = UploadedFile::fake()->create('msds.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $file], $headers)->assertOk();
        $path = $product->refresh()->consumableSpec->sds_file_path;

        $this->deleteJson("/api/v1/app/products/{$product->id}/sds", [], $headers)->assertOk();

        $product->refresh();
        $this->assertNull($product->consumableSpec->sds_file_path);
        $this->assertNull($product->consumableSpec->sds_original_filename);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_sds_is_tenant_scoped(): void
    {
        [, , $product] = $this->setUpTenant();
        $tenantB = $this->makeTenant(['code' => 'SDSB-'.Str::random(4)]);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['product.view', 'product.update']);

        $file = UploadedFile::fake()->create('msds.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/sds", ['file' => $file], $this->authHeaders($tokenB))
            ->assertStatus(404);
        $this->getJson("/api/v1/app/products/{$product->id}/sds", $this->authHeaders($tokenB))->assertStatus(404);
    }
}
