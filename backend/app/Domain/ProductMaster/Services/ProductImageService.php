<?php

namespace App\Domain\ProductMaster\Services;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 14 image-URL sweep: replaces the plain "Image URL" text field
 * with a real JPG/PNG upload. A Product image is tenant-internal catalog
 * data (like VehicleBrandLogoService / VehiclePhotoService), not public
 * branding (unlike TenantLogoService, which deliberately needs an
 * unauthenticated public URL for the favicon/pre-login paint) — so this
 * uses the private `local` disk, served only through an authenticated
 * controller action. `image_url` itself is left untouched; this writes
 * to the separate `image_path`/`image_original_filename` columns only.
 */
class ProductImageService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function upload(Product $product, UploadedFile $file): Product
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => 'Unsupported file type. Only JPG or PNG images are accepted.']);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true)) {
            throw ValidationException::withMessages(['file' => 'Unsupported file extension. Only .jpg, .jpeg, or .png are accepted.']);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages(['file' => 'File exceeds the 5MB maximum size.']);
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "product-images/{$product->tenant_id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        $previousPath = $product->image_path;

        $product->update([
            'image_path' => $path,
            'image_original_filename' => $file->getClientOriginalName(),
        ]);

        if ($previousPath !== null) {
            Storage::disk('local')->delete($previousPath);
        }

        return $product->fresh();
    }

    public function delete(Product $product): Product
    {
        if ($product->image_path !== null) {
            Storage::disk('local')->delete($product->image_path);
        }
        $product->update(['image_path' => null, 'image_original_filename' => null]);

        return $product->fresh();
    }
}
