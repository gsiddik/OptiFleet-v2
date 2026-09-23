<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkOrderPartReturnEvidence;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Return Image / photo URL (optional)" becomes a real JPG/PNG upload —
 * same private-disk, never-trust-the-filename posture as
 * VehicleDocumentService, since a Work Order Return photo is operational
 * evidence, never publicly servable. Uploaded before the return itself is
 * confirmed (removable up to that point), then linked to the
 * WorkOrderPartReturn row WorkOrderPartService::returnPart() creates.
 */
class WorkOrderPartReturnEvidenceService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function upload(WorkOrderPlannedPart $part, UploadedFile $file, string $uploadedByUserId): WorkOrderPartReturnEvidence
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new WorkOrderException('Unsupported file type. Only JPG or PNG images are accepted.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true)) {
            throw new WorkOrderException('Unsupported file extension. Only .jpg, .jpeg, or .png are accepted.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new WorkOrderException('File exceeds the 5MB maximum size.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "work-order-part-returns/{$part->tenant_id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        return WorkOrderPartReturnEvidence::query()->create([
            'tenant_id' => $part->tenant_id,
            'work_order_planned_part_id' => $part->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedByUserId,
        ]);
    }

    public function delete(WorkOrderPartReturnEvidence $evidence): void
    {
        if ($evidence->work_order_part_return_id !== null) {
            throw new WorkOrderException('This evidence is already attached to a confirmed return and can no longer be removed.');
        }

        Storage::disk($evidence->disk)->delete($evidence->path);
        $evidence->delete();
    }
}
