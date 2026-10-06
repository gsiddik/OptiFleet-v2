<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The generated backend catalogs (lang/{en,id}/catalog.php, from docs/i18n/12 + 17 via
 * frontend/scripts/i18n/generate.mjs) hold the same keys in both locales and the same {{params}}.
 */
class TranslationCatalogTest extends TestCase
{
    public function test_english_and_indonesian_catalogs_have_identical_keys_and_parameters(): void
    {
        $en = require __DIR__.'/../../lang/en/catalog.php';
        $id = require __DIR__.'/../../lang/id/catalog.php';

        $this->assertGreaterThan(5000, count($en));
        $this->assertSame(array_keys($en), array_keys($id));
        foreach ($en as $key => $text) {
            preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $text, $a);
            preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $id[$key], $b);
            $this->assertEqualsCanonicalizing(array_unique($a[1]), array_unique($b[1]), $key);
            $this->assertNotSame('', trim($id[$key]), $key);
        }
    }
}
