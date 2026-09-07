<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;

/**
 * Section 3-6: thin wrapper delegating to the Phase 5 configurable
 * numbering engine, preserving this class's call-site signature so
 * WorkOrderService doesn't need to change beyond capturing the returned
 * configuration_version_id.
 */
class WorkOrderNumberService
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    /** @return array{document_number:string, configuration_version_id:string} */
    public function generate(string $tenantId, ?string $branchId = null, ?string $workshopId = null): array
    {
        return $this->numbering->generate('work_order', $tenantId, $branchId, $workshopId);
    }
}
