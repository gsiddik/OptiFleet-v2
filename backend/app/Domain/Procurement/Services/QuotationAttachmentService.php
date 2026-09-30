<?php

namespace App\Domain\Procurement\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Vendor quotation document: PDF, DOC or DOCX. The file type is decided from its CONTENT (never
 * the browser-supplied type) and must agree with the extension; a DOCX must really be a Word
 * package. Stored on the private disk under a server-generated name. The 10 MB ceiling follows
 * the platform's existing document-upload convention (vehicle/product documents) — no
 * procurement-specific limit was defined (open owner decision).
 */
class QuotationAttachmentService
{
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** extension => content MIME types libmagic may report for that format */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];

    /** @return array<string, mixed> attachment_* columns for the quotation row */
    public function store(UploadedFile $file, string $tenantId, ?string $userId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        if (! isset(self::ALLOWED[$extension]) || ! in_array($mime, self::ALLOWED[$extension], true) || ($extension === 'docx' && ! $this->isWordPackage($file))) {
            throw ValidationException::withMessages(['attachment' => 'The quotation document must be a PDF or Microsoft Word (DOC/DOCX) file.']);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages(['attachment' => 'The quotation document may not be larger than 10 MB.']);
        }

        $path = $file->storeAs("vendor-quotations/{$tenantId}", Str::uuid().'.'.$extension, ['disk' => 'local']);

        return [
            'attachment_disk' => 'local',
            'attachment_path' => $path,
            'attachment_original_filename' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
            'attachment_mime_type' => $extension === 'docx' ? self::ALLOWED['docx'][0] : ($extension === 'doc' ? 'application/msword' : 'application/pdf'),
            'attachment_size' => $file->getSize(),
            'attachment_uploaded_by' => $userId,
            'attachment_uploaded_at' => now(),
        ];
    }

    private function isWordPackage(UploadedFile $file): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return $file->getMimeType() === self::ALLOWED['docx'][0];
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }
        $isWord = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $isWord;
    }
}
