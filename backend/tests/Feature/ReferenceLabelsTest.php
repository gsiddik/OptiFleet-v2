<?php

namespace Tests\Feature;

use App\Domain\Shared\Support\ReferenceLabels;
use Database\Seeders\ComponentGroupSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\PlatformSuperadminRoleSeeder;
use Database\Seeders\ProductReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * i18n structural preparation S8: every platform-seeded reference value is identified by its canonical
 * code and has a translation key whose English text is the seeded name. No *_en / *_id columns exist.
 */
class ReferenceLabelsTest extends TestCase
{
    private const DATASET = __DIR__.'/../../../docs/i18n/12-en-id-translation-dataset-final.csv';

    public function test_every_seeded_system_value_has_a_translation_key_matching_its_name(): void
    {
        $this->seed([MasterDataSeeder::class, ComponentGroupSeeder::class, ProductReferenceDataSeeder::class, PlatformSuperadminRoleSeeder::class]);

        $seeded = [];
        foreach (array_keys(ReferenceLabels::SYSTEM_VALUES) as $table) {
            $query = DB::table($table)->select('code', 'name');
            if ($table === 'roles') {
                $query->whereNotNull('code');
            } elseif ($table !== 'modules') {
                $query->whereNull('tenant_id')->where('is_system', true);
            }
            foreach ($query->get() as $row) {
                $seeded[$table][$row->code] = $row->name;
                $this->assertSame(
                    ReferenceLabels::SYSTEM_VALUES[$table][$row->code][1] ?? null,
                    $row->name,
                    "{$table}.{$row->code} is registered with its seeded name",
                );
                $this->assertFalse(DB::getSchemaBuilder()->hasColumn($table, 'name_id'), "{$table} has no duplicated language column");
            }
        }
        foreach (ReferenceLabels::SYSTEM_VALUES as $table => $values) {
            $this->assertEqualsCanonicalizing(array_keys($values), array_keys($seeded[$table] ?? []), "{$table} registry matches the seeders");
        }
    }

    public function test_every_key_exists_in_the_dataset_with_the_seeded_english_text(): void
    {
        $dataset = [];
        $handle = fopen(self::DATASET, 'r');
        $header = fgetcsv($handle, escape: '\\');
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $row = array_combine($header, $row);
            $dataset[$row['translation_key']] = $row;
        }
        fclose($handle);

        foreach (ReferenceLabels::SYSTEM_VALUES + ['role descriptions' => ReferenceLabels::SYSTEM_ROLE_DESCRIPTIONS] as $table => $values) {
            foreach ($values as $code => [$key, $name]) {
                $this->assertArrayHasKey($key, $dataset, "{$table}.{$code}");
                $this->assertSame($name, $dataset[$key]['source_text_en'], "{$table}.{$code} English");
                $this->assertNotSame('', $dataset[$key]['translated_text_id'], "{$table}.{$code} Indonesian");
            }
        }
    }

    public function test_a_renamed_value_is_shown_as_stored(): void
    {
        $this->assertSame('masterData.masterData.truck', ReferenceLabels::key('vehicle_categories', 'VC-TRUCK'));
        $this->assertSame('masterData.masterData.truck', ReferenceLabels::key('vehicle_categories', 'VC-TRUCK', 'Truck'));
        $this->assertNull(ReferenceLabels::key('vehicle_categories', 'VC-TRUCK', 'Prime Mover'));
        $this->assertNull(ReferenceLabels::key('vehicle_categories', 'TENANT-OWN'));
    }
}
