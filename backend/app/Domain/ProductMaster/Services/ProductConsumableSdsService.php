<?php

namespace App\Domain\ProductMaster\Services;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductConsumableSpec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Next Improvement Tenant Portal - Products": Consumable's "Safety Data
 * Sheet | O | File Upload | SDS/MSDS jika tersedia" — the
 * sds_file_path/sds_original_filename columns on ProductConsumableSpec
 * already existed (accepted by ProductSpecificationService as plain
 * nullable strings) but nothing ever populated them: no upload service,
 * controller, route, or frontend field existed anywhere in the
 * repository. Same posture as VehicleBrandLogoService: never trust the
 * client filename, private `local` disk only (a hazmat safety document is
 * never publicly servable), single file overwritten on re-upload — no new
 * migration needed since the two existing columns are sufficient (disk is
 * always `local`, so it does not need its own column).
 */
class ProductConsumableSdsService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

    public function upload(Product $product, UploadedFile $file): ProductConsumableSpec
    {
        $spec = $this->specFor($product);

        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => 'Unsupported file type. Only JPEG, PNG, WEBP images or PDF are accepted.']);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages(['file' => 'File exceeds the 10MB maximum size.']);
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "product-consumable-sds/{$product->tenant_id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        $previousPath = $spec->sds_file_path;

        $spec->update([
            'sds_file_path' => $path,
            'sds_original_filename' => $file->getClientOriginalName(),
        ]);

        if ($previousPath !== null) {
            Storage::disk('local')->delete($previousPath);
        }

        return $spec->fresh();
    }

    public function delete(Product $product): ProductConsumableSpec
    {
        $spec = $this->specFor($product);

        if ($spec->sds_file_path !== null) {
            Storage::disk('local')->delete($spec->sds_file_path);
        }
        $spec->update(['sds_file_path' => null, 'sds_original_filename' => null]);

        return $spec->fresh();
    }

    private function specFor(Product $product): ProductConsumableSpec
    {
        if ($product->product_type !== 'CONSUMABLE') {
            throw ValidationException::withMessages(['product' => 'Safety Data Sheet upload only applies to Consumable products.']);
        }

        return ProductConsumableSpec::query()->where('product_id', $product->id)->firstOrFail();
    }
}
