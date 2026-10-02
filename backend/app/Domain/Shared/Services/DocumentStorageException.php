<?php

namespace App\Domain\Shared\Services;

use RuntimeException;

/**
 * The server could not write an uploaded document (e.g. the storage directory is not writable by
 * the application user). Rendered as 503 with a generic message — the storage path is logged,
 * never returned to the client. Thrown before the related record is saved, so nothing is persisted.
 */
class DocumentStorageException extends RuntimeException
{
    public const USER_MESSAGE = 'The document could not be saved on the server, so no changes were made. Please try again or contact your administrator.';
}
