<?php

namespace App\Jobs\Concerns;

use App\Domain\Entitlement\Services\EntitlementService;

/**
 * Background work started under an entitlement is checked again when it runs (audit A02/R05): a job that waited in the queue
 * must not run for a module OptiNexus has since withdrawn. Tenants that are not centrally governed are unaffected, and an
 * unreachable OptiNexus counts as "not entitled" (fail closed).
 */
trait ChecksCentralEntitlement
{
    protected function stillEntitled(string $tenantId, string $moduleCode): bool
    {
        return app(EntitlementService::class)->centralModuleStillHeld($tenantId, $moduleCode);
    }
}
