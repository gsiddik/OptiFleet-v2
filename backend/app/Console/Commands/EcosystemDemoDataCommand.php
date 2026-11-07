<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * The OptiFleet part of the demo ecosystem (see `ecosystem:seed-demo` in OptiNexus): the tenant linked to OptiNexus (as the first single
 * sign-on of its administrator would create it), two branches with a workshop and a warehouse each. The vehicles are not typed here: they
 * arrive from OptiDeli through the real master events (drafts to complete), so run `ecosystem:demo-data` of OptiDeli after this one, or
 * its vehicle republish. Safe to run again. Local installations only.
 */
class EcosystemDemoDataCommand extends Command
{
    protected $signature = 'ecosystem:demo-data {--nexus-tenant-id= : Id of the tenant in OptiNexus} {--code=DEMO-EKOSISTEM} {--name=Demo Ekosistem}';

    protected $description = 'Load the OptiFleet part of the demo ecosystem (linked tenant, branches, workshops, warehouses)';

    public function handle(TenantContext $context): int
    {
        if (! app()->environment(['local', 'testing']) && ! filter_var(env('ECOSYSTEM_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Demo data is for local installations. Set ECOSYSTEM_ALLOW_DEMO_SEED=true to allow it elsewhere.');

            return self::FAILURE;
        }
        $nexusId = (string) $this->option('nexus-tenant-id');
        if ($nexusId === '') {
            $this->error('Give --nexus-tenant-id (the id printed by ecosystem:seed-demo in OptiNexus).');

            return self::FAILURE;
        }
        $tenant = Tenant::withTrashed()->where('optinexus_tenant_id', $nexusId)->first();
        if (! $tenant) {
            $code = (string) $this->option('code');
            if (Tenant::withTrashed()->where('code', $code)->exists()) {
                $code = 'NEXUS-'.$nexusId;
            }
            $tenant = new Tenant;
            $tenant->forceFill(['code' => $code, 'name' => (string) $this->option('name'), 'status' => 'ACTIVE', 'optinexus_tenant_id' => $nexusId, 'governance_mode' => 'CENTRAL'])->save();
            $this->line('tenant linked');
        }

        $previous = $context->tenantId();
        $context->setTenantId($tenant->id);
        try {
            foreach ([['DEMO-JKT', 'Cabang Jakarta', 'Jakarta', 'DKI Jakarta'], ['DEMO-BDG', 'Cabang Bandung', 'Bandung', 'Jawa Barat']] as [$code, $name, $city, $province]) {
                $branch = Branch::query()->updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'city' => $city, 'province' => $province, 'status' => 'ACTIVE']);
                $workshop = Workshop::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code.'-WS1'],
                    ['branch_id' => $branch->id, 'name' => $name.' Workshop', 'workshop_type' => 'INTERNAL', 'capacity' => 6, 'number_of_service_bays' => 3, 'status' => 'ACTIVE']
                );
                Warehouse::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code.'-WH1'],
                    ['branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'name' => $name.' Warehouse', 'warehouse_type' => 'WORKSHOP', 'status' => 'ACTIVE']
                );
            }
        } finally {
            $context->setTenantId($previous);
        }
        $this->info('OptiFleet demo data ready for '.$tenant->name.'.');

        return self::SUCCESS;
    }
}
