<?php

namespace App\Domain\Governance\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scope `locallyGoverned` for models with a `tenant_id`: leaves out the tenants whose governance is CENTRAL. OptiNexus bills those, so
 * OptiFleet's own billing and dunning jobs must not touch them (wave 6, audit G9: no commercial data twice).
 */
trait LocallyGoverned
{
    /** @param  Builder<static>  $query */
    public function scopeLocallyGoverned(Builder $query): void
    {
        $query->whereNotIn($query->getModel()->qualifyColumn('tenant_id'), fn ($sub) => $sub->select('id')->from('tenants')->where('governance_mode', 'CENTRAL'));
    }
}
