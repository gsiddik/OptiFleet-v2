<?php

namespace App\Domain\MasterData\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Platform baseline for Component Groups ("Component Group Master"
 * improvement). Shared by the one-off abbreviation backfill migration and
 * the rerunnable ComponentGroupSeeder so the two can never drift apart.
 *
 * `code` is the existing stable machine identifier (CG-*) — it is never
 * renamed, because it is what seeders, mappings and tenant data key on.
 * `legacy_names` are the exact names earlier releases seeded; a platform row
 * whose name still equals one of them has never been curated by a human and
 * may be renamed to the new `name` once, by the backfill migration only.
 */
final class ComponentGroupBaseline
{
    public const ABBREVIATION_PATTERN = '/^[A-Z]{3}$/';

    /**
     * @return array<int, array{code: string, abbreviation: string, name: string, legacy_names: array<int, string>, parent: ?string, sequence: int}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'CG-ENGINE', 'abbreviation' => 'ENG', 'name' => 'Engine', 'legacy_names' => [], 'parent' => null, 'sequence' => 10],
            ['code' => 'CG-ENGINE-LUBE', 'abbreviation' => 'LUB', 'name' => 'Lubrication System', 'legacy_names' => [], 'parent' => 'CG-ENGINE', 'sequence' => 10],
            ['code' => 'CG-ENGINE-COOL', 'abbreviation' => 'COL', 'name' => 'Cooling System', 'legacy_names' => [], 'parent' => 'CG-ENGINE', 'sequence' => 20],
            ['code' => 'CG-ENGINE-FUEL', 'abbreviation' => 'FUL', 'name' => 'Fuel System', 'legacy_names' => [], 'parent' => 'CG-ENGINE', 'sequence' => 30],
            ['code' => 'CG-ENGINE-EXHAUST', 'abbreviation' => 'EXH', 'name' => 'Exhaust System', 'legacy_names' => [], 'parent' => 'CG-ENGINE', 'sequence' => 40],
            ['code' => 'CG-CLUTCH', 'abbreviation' => 'CLT', 'name' => 'Clutch & Torque Converter', 'legacy_names' => ['Clutch System / Torque Converter'], 'parent' => null, 'sequence' => 20],
            ['code' => 'CG-TRANS', 'abbreviation' => 'TRN', 'name' => 'Transmission System', 'legacy_names' => [], 'parent' => null, 'sequence' => 30],
            ['code' => 'CG-STEER', 'abbreviation' => 'STR', 'name' => 'Steering System', 'legacy_names' => [], 'parent' => null, 'sequence' => 40],
            ['code' => 'CG-AXLE', 'abbreviation' => 'AXL', 'name' => 'Travel Drive & Axle Assembly', 'legacy_names' => ['Travel Drive / Axle Assy'], 'parent' => null, 'sequence' => 50],
            ['code' => 'CG-FRAME', 'abbreviation' => 'FRM', 'name' => 'Main Frame, Guard & Bogie', 'legacy_names' => ['Main Frame & Guard / Bogie'], 'parent' => null, 'sequence' => 60],
            ['code' => 'CG-ELEC', 'abbreviation' => 'ELC', 'name' => 'Electrical & Electronic System', 'legacy_names' => ['Electrical System'], 'parent' => null, 'sequence' => 70],
            ['code' => 'CG-BRAKE', 'abbreviation' => 'BRK', 'name' => 'Brake System', 'legacy_names' => [], 'parent' => null, 'sequence' => 80],
            ['code' => 'CG-SUSP', 'abbreviation' => 'SUS', 'name' => 'Suspension System', 'legacy_names' => [], 'parent' => null, 'sequence' => 90],
            ['code' => 'CG-HYD', 'abbreviation' => 'HYD', 'name' => 'Hydraulic System', 'legacy_names' => [], 'parent' => null, 'sequence' => 100],
            ['code' => 'CG-PNEU', 'abbreviation' => 'PNE', 'name' => 'Pneumatic System', 'legacy_names' => [], 'parent' => null, 'sequence' => 110],
            ['code' => 'CG-SWING', 'abbreviation' => 'SWG', 'name' => 'Swing System', 'legacy_names' => [], 'parent' => null, 'sequence' => 120],
            ['code' => 'CG-UC', 'abbreviation' => 'UCR', 'name' => 'Undercarriage', 'legacy_names' => ['Under Carriage'], 'parent' => null, 'sequence' => 130],
            ['code' => 'CG-TYRE', 'abbreviation' => 'WTY', 'name' => 'Wheel & Tyre System', 'legacy_names' => ['Tyre'], 'parent' => null, 'sequence' => 140],
            ['code' => 'CG-ATTACH', 'abbreviation' => 'ATC', 'name' => 'Attachment & Work Equipment', 'legacy_names' => ['Attachment / Work Equipment'], 'parent' => null, 'sequence' => 150],
            ['code' => 'CG-ACC', 'abbreviation' => 'OAC', 'name' => 'Optional Accessories', 'legacy_names' => [], 'parent' => null, 'sequence' => 160],
            ['code' => 'CG-HVAC', 'abbreviation' => 'HVA', 'name' => 'HVAC / Air Conditioning System', 'legacy_names' => [], 'parent' => null, 'sequence' => 170],
            ['code' => 'CG-BODY', 'abbreviation' => 'BDC', 'name' => 'Body & Cabin', 'legacy_names' => [], 'parent' => null, 'sequence' => 180],
            ['code' => 'CG-GLASS', 'abbreviation' => 'GLS', 'name' => 'Glass & Washer System', 'legacy_names' => [], 'parent' => null, 'sequence' => 190],
            ['code' => 'CG-SRS', 'abbreviation' => 'SRS', 'name' => 'Safety & Restraint System', 'legacy_names' => [], 'parent' => null, 'sequence' => 200],
            ['code' => 'CG-EV-HV', 'abbreviation' => 'EHV', 'name' => 'EV High Voltage System', 'legacy_names' => [], 'parent' => null, 'sequence' => 210],
        ];
    }

    /**
     * True when any Component Group row — any tenant, platform, or soft
     * deleted — already carries this abbreviation. Abbreviations are never
     * reused because they may already appear inside historical SKUs.
     */
    public static function abbreviationTaken(string $abbreviation, ?string $exceptId = null): bool
    {
        return DB::table('component_groups')
            ->where('abbreviation', $abbreviation)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * Fill a missing abbreviation on the existing platform row for a baseline
     * code. Never overwrites a non-null abbreviation, never touches name,
     * status, parent or deleted_at. Ambiguous data (the same platform code
     * present more than once, e.g. an active and a soft-deleted twin) is
     * reported and skipped rather than guessed.
     *
     * @return 'filled'|'kept'|'missing'|'ambiguous'|'collision'
     */
    public static function fillMissingAbbreviation(array $definition): string
    {
        $rows = DB::table('component_groups')->whereNull('tenant_id')->where('code', $definition['code'])->get();

        if ($rows->isEmpty()) {
            return 'missing';
        }
        if ($rows->count() > 1) {
            Log::warning('Component Group baseline: ambiguous platform code, abbreviation not assigned.', ['code' => $definition['code']]);

            return 'ambiguous';
        }

        $row = $rows->first();
        if ($row->abbreviation !== null) {
            return 'kept';
        }
        if (self::abbreviationTaken($definition['abbreviation'], $row->id)) {
            Log::warning('Component Group baseline: abbreviation already used by another group, not assigned.', $definition);

            return 'collision';
        }

        DB::table('component_groups')->where('id', $row->id)->whereNull('abbreviation')->update([
            'abbreviation' => $definition['abbreviation'],
            'updated_at' => now(),
        ]);

        return 'filled';
    }
}
