<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deployment-readiness audit: the 5 default OptiFleet commercial bundles
 * and their published pricing were previously only reachable through
 * CommercialSeeder, which is entirely demo-tenant-oriented (ALPHA/BETA/
 * GAMMA/DELTA subscription scenarios) and was never part of the
 * production bootstrap path. A real deployment still needs a sellable
 * bundle/pricing catalog to exist — this is genuine reference data, not
 * demo data — so this seeder reuses CommercialSeeder's own
 * seedBundlesAndPricing() (now public) without duplicating its logic or
 * running any of CommercialSeeder's demo-tenant methods.
 */
class CommercialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        (new CommercialSeeder)->seedBundlesAndPricing();
    }
}
