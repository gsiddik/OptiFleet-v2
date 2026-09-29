<?php

use App\Domain\MasterData\Support\ComponentGroupBaseline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off, controlled data migration for EXISTING platform Component Groups:
 *  - fills the baseline abbreviation, matched by the stable `code` (never by
 *    name), only where the abbreviation is still NULL;
 *  - renames a platform group to its new baseline name only when its current
 *    name is still exactly the name an earlier release seeded (i.e. nobody
 *    has curated it since).
 * IDs never change, so every reference stays valid. Tenant-owned groups are
 * not touched. Rerunnable: a second run is a no-op. New baseline groups are
 * created by ComponentGroupSeeder, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (ComponentGroupBaseline::definitions() as $definition) {
            if (ComponentGroupBaseline::fillMissingAbbreviation($definition) === 'ambiguous') {
                continue;
            }

            if ($definition['legacy_names'] !== []) {
                DB::table('component_groups')
                    ->whereNull('tenant_id')
                    ->where('code', $definition['code'])
                    ->whereIn('name', $definition['legacy_names'])
                    ->update(['name' => $definition['name'], 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible by design: the abbreviation column itself is dropped by
        // the previous migration's down(); curated names are never reverted.
    }
};
