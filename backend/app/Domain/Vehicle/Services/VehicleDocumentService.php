<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Same security posture as PaymentSubmissionService::attachProof (Section
 * 7): never trust the client filename, store under a fresh UUID name on
 * the private `local` disk, keep the original name only as display
 * metadata. Retrieval must go through an authenticated, ownership-checked
 * controller action — never a public URL.
 */
class VehicleDocumentService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

    public function upload(Vehicle $vehicle, UploadedFile $file, array $attributes, string $uploadedByUserId): VehicleDocument
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new VehicleException('Unsupported file type. Only JPEG, PNG, WEBP images or PDF are accepted.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new VehicleException('File exceeds the 10MB maximum size.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "vehicle-documents/{$vehicle->tenant_id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        return VehicleDocument::query()->create(array_merge($attributes, [
            'tenant_id' => $vehicle->tenant_id,
            'vehicle_id' => $vehicle->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedByUserId,
        ]));
    }
}
