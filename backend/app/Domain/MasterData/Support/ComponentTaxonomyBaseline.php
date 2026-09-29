<?php

namespace App\Domain\MasterData\Support;

/**
 * Accessor for the committed baseline taxonomy data file
 * (database/data/component_taxonomy.php), transcribed 1:1 from
 * docs/reference/component-taxonomy.md.
 */
final class ComponentTaxonomyBaseline
{
    /** @return array<string, array<int, array{code: string, name: string, subcategories: array<int, array{code: string, name: string, description: ?string, item_types: array<int, string>, basis: string, line: int}>}>> */
    public static function definitions(): array
    {
        return require database_path('data/component_taxonomy.php');
    }

    /** @return array{groups: int, categories: int, subcategories: int, item_type_mappings: int} */
    public static function statistics(): array
    {
        $categories = $subcategories = $mappings = 0;
        foreach (self::definitions() as $groupCategories) {
            $categories += count($groupCategories);
            foreach ($groupCategories as $category) {
                $subcategories += count($category['subcategories']);
                foreach ($category['subcategories'] as $subcategory) {
                    $mappings += count($subcategory['item_types']);
                }
            }
        }

        return ['groups' => count(self::definitions()), 'categories' => $categories, 'subcategories' => $subcategories, 'item_type_mappings' => $mappings];
    }
}
