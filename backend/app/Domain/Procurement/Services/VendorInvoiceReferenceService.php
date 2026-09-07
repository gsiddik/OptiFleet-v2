<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Section 23: procurement traceability only — deliberately separate from
 * Phase 2's SaaS Invoice (a different domain entirely: what the vendor
 * billed OptiFleet's tenant, not what the tenant bills its own customers).
 * Same secure-attachment posture as VehicleDocumentService: private disk,
 * UUID filename, original name kept only as display metadata.
 */
class VendorInvoiceReferenceService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function create(Partner $partner, array $attributes, ?UploadedFile $file = null): VendorInvoiceReference
    {
        $attachment = [];
        if ($file) {
            if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                throw new ProcurementException('Unsupported file type. Only JPEG, PNG, WEBP images or PDF are accepted.');
            }
            if ($file->getSize() > self::MAX_SIZE_BYTES) {
                throw new ProcurementException('File exceeds the 10MB maximum size.');
            }
            $extension = $file->guessExtension() ?: 'bin';
            $path = $file->storeAs("vendor-invoices/{$partner->tenant_id}", Str::uuid().'.'.$extension, ['disk' => 'local']);
            $attachment = ['attachment_path' => $path, 'attachment_disk' => 'local'];
        }

        return VendorInvoiceReference::query()->create(array_merge($attributes, $attachment, [
            'tenant_id' => $partner->tenant_id,
            'partner_id' => $partner->id,
            'status' => 'RECEIVED',
        ]));
    }
}
