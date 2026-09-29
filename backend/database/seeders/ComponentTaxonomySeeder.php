<?php

namespace Database\Seeders;

use App\Domain\MasterData\Support\ComponentTaxonomyBaseline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production-safe baseline for the Component Classification taxonomy
 * (Category / Assembly and Subcategory / Component Family beneath the
 * platform Component Groups), 334 categories / 917 subcategories.
 *
 * Natural keys: platform Category = (component_group_id, code); platform
 * Subcategory = (component_category_id, code). A key that already exists —
 * active OR soft-deleted — is left exactly as it is (name, description,
 * status, sequence, parent, Item Type mapping, deleted_at): the seeder only
 * ever ADDS baseline rows that have never existed, so an intentionally
 * deleted row is never resurrected, curated rows are never overwritten, and
 * tenant/custom rows are never touched. New rows added to the data file in a
 * future release are picked up on the next deploy.
 *
 * Baseline rows are bulk-inserted (not individually audited) — the data file
 * in version control is their provenance.
 */
class ComponentTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('component_taxonomy_seeder'))");

            $groups = DB::table('component_groups')->whereNull('tenant_id')->get(['id', 'code'])->groupBy('code');
            $categories = DB::table('component_categories')->whereNull('tenant_id')->get(['id', 'component_group_id', 'code'])
                ->keyBy(fn ($row) => $row->component_group_id.'|'.$row->code);
            $subcategoryKeys = DB::table('component_subcategories')->whereNull('tenant_id')->get(['component_category_id', 'code'])
                ->mapWithKeys(fn ($row) => [$row->component_category_id.'|'.$row->code => true]);

            $now = now();
            $newCategories = $newSubcategories = $newItemTypes = [];

            foreach (ComponentTaxonomyBaseline::definitions() as $groupCode => $groupCategories) {
                $matches = $groups->get($groupCode);
                if ($matches === null || $matches->count() !== 1) {
                    $this->command?->warn("Component taxonomy for {$groupCode} not seeded: platform Component Group missing or ambiguous.");

                    continue;
                }
                $groupId = $matches->first()->id;

                foreach (array_values($groupCategories) as $categoryIndex => $category) {
                    $key = $groupId.'|'.$category['code'];
                    $categoryId = $categories->get($key)?->id;
                    if ($categoryId === null) {
                        $categoryId = (string) Str::uuid();
                        $newCategories[] = [
                            'id' => $categoryId, 'tenant_id' => null, 'component_group_id' => $groupId,
                            'code' => $category['code'], 'name' => $category['name'], 'description' => null,
                            'sequence' => ($categoryIndex + 1) * 10, 'is_system' => true, 'status' => 'ACTIVE',
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                    }

                    foreach (array_values($category['subcategories']) as $subIndex => $subcategory) {
                        if ($subcategoryKeys->has($categoryId.'|'.$subcategory['code'])) {
                            continue;
                        }
                        $subcategoryId = (string) Str::uuid();
                        $newSubcategories[] = [
                            'id' => $subcategoryId, 'tenant_id' => null, 'component_category_id' => $categoryId,
                            'code' => $subcategory['code'], 'name' => $subcategory['name'], 'description' => $subcategory['description'],
                            'sequence' => ($subIndex + 1) * 10, 'is_system' => true, 'status' => 'ACTIVE',
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                        foreach ($subcategory['item_types'] as $itemType) {
                            $newItemTypes[] = ['component_subcategory_id' => $subcategoryId, 'item_type' => $itemType, 'created_at' => $now, 'updated_at' => $now];
                        }
                    }
                }
            }

            foreach (array_chunk($newCategories, 500) as $chunk) {
                DB::table('component_categories')->insert($chunk);
            }
            foreach (array_chunk($newSubcategories, 500) as $chunk) {
                DB::table('component_subcategories')->insert($chunk);
            }
            foreach (array_chunk($newItemTypes, 500) as $chunk) {
                DB::table('component_subcategory_item_types')->insert($chunk);
            }
        });
    }
}
