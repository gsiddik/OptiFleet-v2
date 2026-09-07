<?php

namespace App\Domain\MaintenanceRequest\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;

/** Section 3-6: thin wrapper delegating to the Phase 5 configurable numbering engine. */
class MaintenanceRequestNumberService
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    /** @return array{document_number:string, configuration_version_id:string} */
    public function generate(string $tenantId, ?string $branchId = null): array
    {
        return $this->numbering->generate('maintenance_request', $tenantId, $branchId);
    }
}
