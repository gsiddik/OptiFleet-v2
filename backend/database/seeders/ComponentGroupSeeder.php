<?php

namespace Database\Seeders;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Support\ComponentGroupBaseline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Production-safe baseline for the platform Component Group master.
 *
 * The seeder only PROVIDES defaults; it never takes ownership back:
 *  - missing baseline code  -> created (platform-owned, ACTIVE);
 *  - existing baseline code -> only a NULL abbreviation is filled; name,
 *    description, status, parent, sequence and deleted_at are left exactly
 *    as the platform admin last set them;
 *  - soft-deleted baseline code -> left deleted (never restored/recreated);
 *  - tenant groups and custom platform groups -> never touched;
 *  - no truncate, no delete, no duplicate (lookup is by stable code,
 *    soft-deleted rows included).
 * A baseline abbreviation already used by some other group is skipped and
 * logged rather than duplicated. Safe to run on every deployment.
 */
class ComponentGroupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ComponentGroupBaseline::definitions() as $definition) {
            DB::transaction(function () use ($definition) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['component_group_abbreviation:'.$definition['abbreviation']]);

                $exists = ComponentGroup::withTrashed()->whereNull('tenant_id')->where('code', $definition['code'])->exists();
                if ($exists) {
                    ComponentGroupBaseline::fillMissingAbbreviation($definition);

                    return;
                }

                if (ComponentGroupBaseline::abbreviationTaken($definition['abbreviation'])) {
                    $this->command?->warn("Component Group {$definition['code']} not seeded: abbreviation {$definition['abbreviation']} is already in use.");

                    return;
                }

                $parentId = $definition['parent'] === null ? null : ComponentGroup::query()
                    ->whereNull('tenant_id')
                    ->where('code', $definition['parent'])
                    ->value('id');

                ComponentGroup::query()->create([
                    'tenant_id' => null,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'abbreviation' => $definition['abbreviation'],
                    'parent_id' => $parentId,
                    'sequence' => $definition['sequence'],
                    'is_system' => true,
                    'status' => 'ACTIVE',
                ]);
            });
        }
    }
}
