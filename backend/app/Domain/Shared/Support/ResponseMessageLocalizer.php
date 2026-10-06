<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Renders English API messages in the request locale at the response boundary (i18n).
 *
 * Domain code raises its errors in English (the canonical text of dataset 12). For a non-English request,
 * a message that is a dataset English text — exactly, or as a `{{param}}` template with its values filled
 * in — is replaced by the translation of the same key, with the same values. Everything else is returned
 * unchanged: text that is already localized (Laravel validation), tenant-entered text, unknown messages.
 *
 * Additive and backward compatible: English requests are untouched, `codes`, statuses and every other
 * field keep their values, and only `message` and the strings of `errors` are rendered.
 */
class ResponseMessageLocalizer
{
    /** @var array<string, array{exact: array<string, string>, templates: list<array{regex: string, params: list<string>, target: string}>}> */
    private static array $index = [];

    public static function localize(string $message, string $locale): string
    {
        if ($locale === 'en' || $message === '') {
            return $message;
        }
        // Laravel's validation summary: "<first error> (and N more errors)".
        if (preg_match('/^(.+) \(and (\d+) more errors?\)$/su', $message, $summary)) {
            $first = self::localize($summary[1], $locale);
            $more = Lang::get('catalog.errors.common.andCountMoreErrors', [], $locale, false);

            return is_string($more) && $more !== 'catalog.errors.common.andCountMoreErrors' ? $first.' '.strtr($more, ['{{count}}' => $summary[2]]) : $message;
        }
        $index = self::index($locale);
        if (isset($index['exact'][$message])) {
            return $index['exact'][$message];
        }
        foreach ($index['templates'] as $template) {
            if (preg_match($template['regex'], $message, $m)) {
                $values = [];
                foreach ($template['params'] as $i => $name) {
                    $values['{{'.$name.'}}'] = $m[$i + 1];
                }

                return strtr($template['target'], $values);
            }
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function localizePayload(array $payload, string $locale): array
    {
        if ($locale === 'en') {
            return $payload;
        }
        if (isset($payload['message']) && is_string($payload['message'])) {
            $payload['message'] = self::localize($payload['message'], $locale);
        }
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            foreach ($payload['errors'] as $field => $messages) {
                if (is_array($messages)) {
                    $payload['errors'][$field] = array_map(fn ($m) => is_string($m) ? self::localize($m, $locale) : $m, $messages);
                } elseif (is_string($messages)) {
                    $payload['errors'][$field] = self::localize($messages, $locale);
                }
            }
        }

        return $payload;
    }

    /**
     * English text → target text, built once per locale from the generated catalogs. A text whose keys
     * disagree on the translation is ambiguous and left out (shown in English rather than guessed).
     *
     * @return array{exact: array<string, string>, templates: list<array{regex: string, params: list<string>, target: string}>}
     */
    private static function index(string $locale): array
    {
        if (isset(self::$index[$locale])) {
            return self::$index[$locale];
        }
        $english = (array) Lang::get('catalog', [], 'en', false);
        $target = (array) Lang::get('catalog', [], $locale, false);
        $byText = [];
        foreach ($english as $key => $text) {
            if (is_string($text) && isset($target[$key]) && is_string($target[$key]) && $target[$key] !== '') {
                $byText[$text][$target[$key]] = true;
            }
        }
        $exact = [];
        $templates = [];
        foreach ($byText as $text => $translations) {
            if (count($translations) !== 1) {
                continue;
            }
            $translation = (string) array_key_first($translations);
            $text = (string) $text;
            if (! str_contains($text, '{{')) {
                $exact[$text] = $translation;

                continue;
            }
            // Templates need enough fixed wording to be recognized safely (not "{{a}} {{b}}").
            $literal = trim(preg_replace('/\{\{\s*\w+\s*\}\}/', ' ', $text));
            if (str_word_count($literal) < 3) {
                continue;
            }
            preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $text, $names);
            if (count($names[1]) !== count(array_unique($names[1]))) {
                continue; // a repeated placeholder name cannot carry two values (dataset QA finding)
            }
            $parts = preg_split('/\{\{\s*\w+\s*\}\}/', $text);
            $regex = '/^'.implode('(.+?)', array_map(fn ($p) => preg_quote($p, '/'), $parts)).'$/su';
            $templates[] = ['regex' => $regex, 'params' => $names[1], 'target' => $translation, 'fixed' => strlen($literal)];
        }
        // The most specific template first.
        usort($templates, fn ($a, $b) => $b['fixed'] <=> $a['fixed']);

        return self::$index[$locale] = [
            'exact' => $exact,
            'templates' => array_map(fn ($t) => ['regex' => $t['regex'], 'params' => $t['params'], 'target' => $t['target']], $templates),
        ];
    }
}
