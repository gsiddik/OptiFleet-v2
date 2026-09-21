<?php

namespace App\Domain\MasterData\Services;

use App\Domain\MasterData\Models\VehicleBrand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Next Improvement Tenant Portal - Products": Vehicle Brand's plain-text
 * Logo URL becomes an actual upload accepting JPG/PNG. Same posture as
 * VehiclePhotoService: never trust the client filename, store under a
 * fresh UUID name on the private `local` disk, serve only through an
 * authenticated controller action.
 */
class VehicleBrandLogoService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function upload(VehicleBrand $brand, UploadedFile $file): VehicleBrand
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

        $scope = $brand->tenant_id ?? 'platform';
        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "vehicle-brand-logos/{$scope}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        $previousPath = $brand->logo_path;
        $previousDisk = $brand->logo_disk;

        $brand->update([
            'logo_disk' => 'local',
            'logo_path' => $path,
            'logo_mime_type' => $file->getMimeType(),
            'logo_size' => $file->getSize(),
        ]);

        if ($previousPath !== null) {
            Storage::disk($previousDisk)->delete($previousPath);
        }

        return $brand->fresh();
    }
}
