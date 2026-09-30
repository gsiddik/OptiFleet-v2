<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\UsedPartInspectionEvidence;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Used Sparepart Processing evidence photos. Only JPG/PNG up to 3 MB: the type is checked from the
 * file CONTENT (never the browser-supplied type) and the extension must agree. Files are stored on
 * the private disk under a server-generated UUID name — the client filename is display metadata
 * only, so it can never influence the storage path. Photos can be added or removed only while the
 * item awaits inspection; afterwards they are part of the inspection record.
 */
class UsedPartEvidenceService
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    public const MAX_SIZE_BYTES = 3 * 1024 * 1024;

    public function upload(WorkOrderPartReturn $return, UploadedFile $file, string $userId): UsedPartInspectionEvidence
    {
        $this->assertEditable($return);
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new WorkOrderException('Unsupported file type. Only JPG or PNG images are accepted.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
            throw new WorkOrderException('Unsupported file extension. Only .jpg, .jpeg or .png are accepted.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new WorkOrderException('File exceeds the 3 MB maximum size.');
        }

        $extension = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $path = $file->storeAs("used-part-evidence/{$return->tenant_id}", Str::uuid().'.'.$extension, ['disk' => 'local']);

        return UsedPartInspectionEvidence::query()->create([
            'tenant_id' => $return->tenant_id,
            'work_order_part_return_id' => $return->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);
    }

    public function delete(UsedPartInspectionEvidence $evidence): void
    {
        $this->assertEditable($evidence->usedPartReturn);
        Storage::disk($evidence->disk)->delete($evidence->path);
        $evidence->delete();
    }

    private function assertEditable(WorkOrderPartReturn $return): void
    {
        if ($return->disposition_status !== 'PENDING_INSPECTION') {
            throw new WorkOrderException('Evidence photos can only be changed while the item is awaiting inspection.');
        }
    }
}
