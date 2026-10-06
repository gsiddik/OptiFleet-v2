<?php

namespace App\Domain\DocumentGeneration\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Builds the body of a template in another language from its English body (i18n, documents).
 *
 * Every text segment between tags whose text is a `documents.*` dataset English string (template
 * variables included, e.g. "Vehicle: {{vehicle.registration_number}}") is replaced by the same key's
 * translation. Markup, variables and sections ({{#jobs}}…{{/jobs}}) are left exactly as they are, so the
 * localized body renders the same data. Text without a dataset row stays English and is reported by
 * untranslated() — a body is only stored when nothing is left untranslated.
 */
class DocumentTemplateLocalizer
{
    /** @var array<string, array<string, string>> */
    private static array $index = [];

    public static function localize(string $html, string $locale): string
    {
        if ($locale === 'en') {
            return $html;
        }
        $map = self::index($locale);

        return preg_replace_callback('/>([^<>]+)</u', function (array $m) use ($map) {
            $text = $m[1];
            $trimmed = trim($text);
            $translation = $trimmed === '' ? null : self::lookup($map, $trimmed);
            if ($translation === null) {
                return $m[0];
            }
            $leading = substr($text, 0, strlen($text) - strlen(ltrim($text)));
            $trailing = substr($text, strlen(rtrim($text)));

            return '>'.$leading.htmlspecialchars($translation, ENT_NOQUOTES, 'UTF-8', false).$trailing.'<';
        }, $html) ?? $html;
    }

    /** The translation of a segment; a label followed by ":" matches the label row and keeps the colon. */
    private static function lookup(array $map, string $segment): ?string
    {
        $text = self::normalize($segment);
        if (isset($map[$text])) {
            return $map[$text];
        }
        if (str_ends_with($text, ':') && isset($map[rtrim(substr($text, 0, -1))])) {
            return $map[rtrim(substr($text, 0, -1))].':';
        }

        return null;
    }

    /**
     * Text segments of an English body that have no `documents.*` translation for the locale (words outside
     * {{variables}} only; section markers and pure variables are not text).
     *
     * @return list<string>
     */
    public static function untranslated(string $englishHtml, string $locale): array
    {
        $map = self::index($locale);
        preg_match_all('/>([^<>]+)</u', $englishHtml, $segments);
        $left = [];
        foreach ($segments[1] as $segment) {
            $words = trim(preg_replace('/[^A-Za-z]+/', ' ', preg_replace('/\{\{[^}]*\}\}|&[a-z]+;/', ' ', $segment)));
            if (preg_match('/[A-Za-z]{2,}/', $words) && self::lookup($map, $segment) === null) {
                $left[] = self::normalize($segment);
            }
        }

        return array_values(array_unique($left));
    }

    /** Whitespace-collapsed, entity-decoded text (the dataset holds "&" / "→", templates may hold "&amp;" / "&rarr;"). */
    private static function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** English → target; an English text with different translations within a tier is left out (tier 1 wins). */
    private static function index(string $locale): array
    {
        if (isset(self::$index[$locale])) {
            return self::$index[$locale];
        }
        $english = (array) Lang::get('catalog', [], 'en', false);
        $target = (array) Lang::get('catalog', [], $locale, false);
        // Tier 1: the documents.* rows (the template wording). Tier 2: any other row with one unambiguous
        // translation (document titles such as "Warranty Claim" live under their module).
        $tiers = [[], []];
        foreach ($english as $key => $text) {
            if (isset($target[$key]) && is_string($text) && is_string($target[$key]) && $target[$key] !== '') {
                $tiers[str_starts_with((string) $key, 'documents.') ? 0 : 1][self::normalize($text)][$target[$key]] = true;
            }
        }
        $map = [];
        foreach (array_reverse($tiers) as $byText) {
            foreach ($byText as $text => $translations) {
                if (count($translations) === 1) {
                    $map[(string) $text] = (string) array_key_first($translations);
                } else {
                    unset($map[(string) $text]);
                }
            }
        }

        return self::$index[$locale] = $map;
    }
}
