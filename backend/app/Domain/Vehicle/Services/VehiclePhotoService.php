<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Same posture as VehicleDocumentService: never trust the client filename,
 * store under a fresh UUID name on the private `local` disk, serve only
 * through an authenticated controller action. The old file is removed only
 * after the vehicle record has been updated to point at the new one, so a
 * failed update never leaves the vehicle without a working photo.
 */
class VehiclePhotoService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10MB, same limit as vehicle documents.

    public function upload(Vehicle $vehicle, UploadedFile $file): Vehicle
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new VehicleException('Unsupported file type. Only JPG, JPEG, or PNG images are accepted.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true)) {
            throw new VehicleException('Unsupported file extension. Only .jpg, .jpeg, or .png are accepted.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new VehicleException('File exceeds the 10MB maximum size.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "vehicle-photos/{$vehicle->tenant_id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        $previousDisk = $vehicle->photo_disk;
        $previousPath = $vehicle->photo_path;

        $vehicle->update([
            'photo_disk' => 'local',
            'photo_path' => $path,
            'photo_mime_type' => $file->getMimeType(),
            'photo_size' => $file->getSize(),
        ]);

        if ($previousPath !== null) {
            Storage::disk($previousDisk)->delete($previousPath);
        }

        return $vehicle->fresh();
    }
}
