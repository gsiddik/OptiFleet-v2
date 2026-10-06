<?php

namespace App\Domain\DocumentGeneration\Support;

use Closure;

/**
 * One printable document as its controller describes it: what it is, whose it is, where its template
 * is resolved, and how its context is built for a given locale. The controller has already checked
 * tenant, permission and data scope before building one.
 */
final class DocumentSource
{
    /**
     * @param  Closure(string): array  $context  builds the template context in the given locale
     * @param  string|null  $fallbackHtml  platform built-in body used only when no template is published
     */
    public function __construct(
        public readonly string $documentType,
        public readonly string $sourceEntityType,
        public readonly string $sourceEntityId,
        public readonly string $tenantId,
        public readonly string $filename,
        public readonly Closure $context,
        public readonly ?string $branchId = null,
        public readonly ?string $workshopId = null,
        public readonly ?string $warehouseId = null,
        public readonly ?string $recipientPartnerId = null,
        public readonly ?string $fallbackHtml = null,
    ) {}
}
