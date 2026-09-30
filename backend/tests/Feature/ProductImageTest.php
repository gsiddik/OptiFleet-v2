<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 14 image-URL sweep: Product's plain "Image URL" text field
 * becomes a real JPG/PNG upload — the last remaining raw Image URL
 * field in the repository (Tenant Logo, Vehicle Brand Logo, Vehicle
 * Photo, Work Order Return/Removed-Component evidence, and Consumable
 * SDS were all already converted in earlier batches).
 */
class ProductImageTest extends TestCase
{
    private function setUpProduct(): array
    {
        $tenant = $this->makeTenant(['code' => 'PIMG-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update', 'product.delete', 'warehouse.view', 'warehouse.update']);
        $bin = $this->makeWarehouseBin($tenant);

        $productId = $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Brake Pad',
            'product_category_id' => $this->makeProductCategory()->id,
            'product_type' => 'SPARE_PART', 'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $bin->id, 'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => [
                'part_number' => 'PN-1', 'part_type' => 'GENUINE',
                'compatibilities' => [$this->vehicleFit()],
            ],
        ], $this->authHeaders($token))->json('data.id');

        return [$tenant, $token, Product::query()->findOrFail($productId)];
    }

    public function test_image_can_be_uploaded_and_downloaded(): void
    {
        [, $token, $product] = $this->setUpProduct();
        $headers = $this->authHeaders($token);

        $file = UploadedFile::fake()->image('brake-pad.jpg', 200, 200);
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $file], $headers)->assertOk();

        $product->refresh();
        $this->assertNotNull($product->image_path);
        $this->assertSame('brake-pad.jpg', $product->image_original_filename);
        Storage::disk('local')->assertExists($product->image_path);

        $this->getJson("/api/v1/app/products/{$product->id}/image", $headers)->assertOk();
    }

    public function test_image_re_upload_replaces_and_deletes_previous_file(): void
    {
        [, $token, $product] = $this->setUpProduct();
        $headers = $this->authHeaders($token);

        $first = UploadedFile::fake()->image('v1.jpg', 100, 100);
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $first], $headers)->assertOk();
        $firstPath = $product->refresh()->image_path;

        $second = UploadedFile::fake()->image('v2.png', 100, 100);
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $second], $headers)->assertOk();

        $product->refresh();
        $this->assertNotSame($firstPath, $product->image_path);
        Storage::disk('local')->assertMissing($firstPath);
    }

    public function test_image_upload_rejects_disallowed_mime_type(): void
    {
        [, $token, $product] = $this->setUpProduct();

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $file], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_image_upload_requires_permission(): void
    {
        [$tenant, , $product] = $this->setUpProduct();
        [, $noPermToken] = $this->makeTenantUser($tenant, ['product.view']);

        $file = UploadedFile::fake()->image('x.jpg');
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $file], $this->authHeaders($noPermToken))
            ->assertStatus(403);
    }

    public function test_image_can_be_deleted(): void
    {
        [, $token, $product] = $this->setUpProduct();
        $headers = $this->authHeaders($token);
        $file = UploadedFile::fake()->image('x.jpg');
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $file], $headers)->assertOk();
        $path = $product->refresh()->image_path;

        $this->deleteJson("/api/v1/app/products/{$product->id}/image", [], $headers)->assertOk();

        $product->refresh();
        $this->assertNull($product->image_path);
        $this->assertNull($product->image_original_filename);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_image_is_tenant_scoped(): void
    {
        [, , $product] = $this->setUpProduct();
        $tenantB = $this->makeTenant(['code' => 'PIMGB-'.Str::random(4)]);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['product.view', 'product.update']);

        $file = UploadedFile::fake()->image('x.jpg');
        $this->postJson("/api/v1/app/products/{$product->id}/image", ['file' => $file], $this->authHeaders($tokenB))
            ->assertStatus(404);
        $this->getJson("/api/v1/app/products/{$product->id}/image", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_legacy_image_url_field_remains_editable_via_direct_update(): void
    {
        // "Existing tenant-facing behavior must remain backward compatible" — the raw
        // image_url column stays writable through the general update endpoint (same
        // precedent as Vehicle Brand/Company Profile's logo_url), even though the
        // Create/Edit UI no longer exposes it as a text field.
        [, $token, $product] = $this->setUpProduct();

        $this->putJson("/api/v1/app/products/{$product->id}", [
            'image_url' => 'https://example.com/legacy-photo.jpg',
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame('https://example.com/legacy-photo.jpg', $product->refresh()->image_url);
    }

    public function test_product_type_other_is_rejected_on_create(): void
    {
        // Batch 14: OTHER predates the doc's 6-value Item Type dropdown and is
        // deliberately excluded from new creation (the DB CHECK constraint still
        // accepts it, for any pre-existing row's backward compatibility).
        [$tenant, $token] = $this->setUpProduct();
        $bin = $this->makeWarehouseBin($tenant);

        $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Legacy Item',
            'product_category_id' => $this->makeProductCategory()->id,
            'product_type' => 'OTHER', 'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $bin->id,
        ], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['product_type']);
    }
}
