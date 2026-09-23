<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\MasterData\Services\MasterDataException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Unlike VehicleBrandLogoService (private `local` disk, served only through
 * an authenticated controller action), a tenant logo must also render in
 * contexts that cannot carry an Authorization header — the browser favicon
 * `<link>` tag and, before the SPA has fetched /me, the initial page paint.
 * So this stores to the public disk and writes a plain public URL into the
 * existing `Tenant.logo_url` column instead of adding dedicated logo_* columns.
 */
class TenantLogoService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function upload(Tenant $tenant, UploadedFile $file): Tenant
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new MasterDataException('Unsupported file type. Only JPG or PNG images are accepted.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true)) {
            throw new MasterDataException('Unsupported file extension. Only .jpg, .jpeg, or .png are accepted.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new MasterDataException('File exceeds the 5MB maximum size.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "tenant-logos/{$tenant->id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'public']
        );

        $previousUrl = $tenant->logo_url;
        $previousPath = $this->pathFromPublicUrl($previousUrl);

        $tenant->update(['logo_url' => Storage::disk('public')->url($path)]);

        if ($previousPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return $tenant->fresh();
    }

    private function pathFromPublicUrl(?string $url): ?string
    {
        if ($url === null || ! str_contains($url, '/storage/tenant-logos/')) {
            return null;
        }

        $marker = '/storage/';
        $pos = strrpos($url, $marker);

        return $pos !== false ? substr($url, $pos + strlen($marker)) : null;
    }
}
