<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Support\Messages;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * One place for "save an uploaded document together with its record":
 *
 *  - the type is checked from the file CONTENT (not the client name or header) and the size is
 *    capped — the request rules (`mimes`, `max`) are a first gate, this is the authoritative one;
 *  - files go to the private `local` disk under a tenant directory with a UUID name; the
 *    original name is metadata only, and files are served only through authorized endpoints;
 *  - {@see persist()} stores the file(s) first, then runs the DB work in one transaction; if
 *    the transaction fails the stored files are deleted again, so a failed save never leaves
 *    an orphan file, and a saved record never points at a missing file.
 */
class PrivateDocumentStorage
{
    public const DISK = 'local';

    /**
     * Uploads are keyed by the request field the file came from (used for validation errors).
     *
     * @param  array<string, array{file: ?UploadedFile, directory: string, mimes: list<string>, max_bytes: int, label: string}>  $uploads
     * @param  Closure(array<string, ?array{disk: string, path: string, original_name: string, mime_type: string, size: int}>): mixed  $persist
     */
    public function persist(array $uploads, Closure $persist): mixed
    {
        foreach ($uploads as $field => $upload) {
            if ($upload['file']) {
                $this->assertAcceptable($field, $upload['file'], $upload['mimes'], $upload['max_bytes'], $upload['label']);
            }
        }

        $stored = [];
        try {
            foreach ($uploads as $field => $upload) {
                $stored[$field] = $upload['file'] ? $this->store($upload['file'], $upload['directory']) : null;
            }

            return DB::transaction(fn () => $persist($stored));
        } catch (Throwable $e) {
            foreach ($stored as $document) {
                if ($document) {
                    Storage::disk($document['disk'])->delete($document['path']);
                }
            }
            throw $e;
        }
    }

    /**
     * @param  list<string>  $mimes  allowed content types, e.g. ['application/pdf']
     */
    public function assertAcceptable(string $field, UploadedFile $file, array $mimes, int $maxBytes, string $label): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([$field => ["The {$label} could not be uploaded. Please try again."]]);
        }
        if (! in_array($file->getMimeType(), $mimes, true)) {
            throw ValidationException::withMessages([$field => ["The {$label} must be a ".$this->describe($mimes).' file.']]);
        }
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([$field => [Messages::text('validation.shared.fileTooLarge', ['label' => $label, 'maxMb' => round($maxBytes / 1024 / 1024)])]]);
        }
    }

    /**
     * @return array{disk: string, path: string, original_name: string, mime_type: string, size: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $extension = $file->guessExtension() ?: 'bin';
        $path = self::putFileAs($file, $directory, Str::uuid().'.'.$extension);

        return [
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * The one write path for private uploads: stores the file on the private disk or throws
     * {@see DocumentStorageException}. A storage failure (missing permission, unwritable mount, …)
     * is reported to the log with its details and surfaces to the user as a generic 503 — never as
     * a raw filesystem error containing the server path, and never as a silently "stored" record
     * pointing at no file (`storeAs` returns false for some failures instead of throwing).
     */
    public static function putFileAs(UploadedFile $file, string $directory, string $filename): string
    {
        try {
            $path = $file->storeAs($directory, $filename, ['disk' => self::DISK]);
        } catch (FilesystemException $e) {
            report($e);
            throw new DocumentStorageException(DocumentStorageException::USER_MESSAGE, 0, $e);
        }
        if ($path === false) {
            report(new DocumentStorageException("Could not write {$directory}/{$filename} on disk ".self::DISK.'.'));
            throw new DocumentStorageException(DocumentStorageException::USER_MESSAGE);
        }

        return $path;
    }

    /** @param list<string> $mimes */
    private function describe(array $mimes): string
    {
        $names = array_map(fn (string $mime) => match ($mime) {
            'application/pdf' => 'PDF',
            'image/jpeg' => 'JPG/JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WEBP',
            default => $mime,
        }, $mimes);

        return implode(', ', array_unique($names));
    }
}
