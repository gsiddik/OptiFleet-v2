<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoiceFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Same security posture as VehiclePhotoService/VehicleDocumentService:
 * never trust the client filename, store under a fresh UUID name on the
 * private `local` disk, keep the original name only as display metadata.
 * Retrieval must go through an authenticated, tenant-scoped controller
 * action — never a public URL. One service handles all four named file
 * roles this feature ever uploads (Acknowledgement here in Phase 4;
 * Completed Work Order / Vendor Invoice / Payment Proof in Phase 5) since
 * they share the exact same storage mechanics and differ only in their
 * allowed MIME types.
 */
class WorkOrderExternalInvoiceFileService
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

    private const ALLOWED_MIME_TYPES = [
        'ACKNOWLEDGEMENT' => [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/pdf',
        ],
        'COMPLETED_WORK_ORDER' => ['image/jpeg', 'image/png', 'application/pdf'],
        'VENDOR_INVOICE' => ['image/jpeg', 'image/png', 'application/pdf'],
        'PAYMENT_PROOF' => ['image/jpeg', 'image/png', 'application/pdf'],
    ];

    public function upload(WorkOrderExternalInvoice $invoice, string $role, UploadedFile $file, ?string $userId): WorkOrderExternalInvoiceFile
    {
        if (! array_key_exists($role, self::ALLOWED_MIME_TYPES)) {
            throw new WorkOrderException("Unknown file role: {$role}.");
        }
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES[$role], true)) {
            throw new WorkOrderException('Unsupported file type for this upload — check the accepted formats.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new WorkOrderException('File exceeds the 10MB maximum size.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "external-work-order-invoices/{$invoice->tenant_id}/{$invoice->id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        $existing = WorkOrderExternalInvoiceFile::query()
            ->where('external_invoice_id', $invoice->id)
            ->where('file_role', $role)
            ->first();

        $record = WorkOrderExternalInvoiceFile::query()->updateOrCreate(
            ['external_invoice_id' => $invoice->id, 'file_role' => $role],
            [
                'tenant_id' => $invoice->tenant_id,
                'disk' => 'local',
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $userId,
                'uploaded_at' => now(),
            ]
        );

        if ($existing && $existing->path !== $path) {
            Storage::disk($existing->disk)->delete($existing->path);
        }

        return $record;
    }
}
