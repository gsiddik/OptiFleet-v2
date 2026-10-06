<?php

namespace Database\Seeders;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Notification\Services\NotificationTemplateValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Lang;

/**
 * i18n (notifications): publishes a NEW version of every platform default notification template that
 * carries Indonesian wording per channel (`payload.locales.id.channels`, from the notifications.* dataset
 * rows) beside its English content. Delivery renders it for recipients whose language is Indonesian.
 *
 * - Published history is never edited; tenant templates are never touched (platform defaults only).
 * - A template is localized only when every subject/body is a dataset English string — a platform admin's
 *   edited wording has no translation and is left as it is (no half-translated template).
 * - Variables ({{vehicle.registration_number}}, …) are identical in both languages, so the same data renders.
 * - Idempotent: does nothing once the published version has `locales.id`.
 */
class AddLocalizedNotificationTemplatesSeeder extends Seeder
{
    public const LOCALE = 'id';

    public function run(): void
    {
        $service = app(ConfigurationService::class);
        $validator = app(NotificationTemplateValidator::class);
        $map = self::translations(self::LOCALE);

        $sets = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')
            ->where('type', ConfigurationSet::TYPE_NOTIFICATION)->where('scope_type', 'TENANT')->whereNull('scope_resource_id')->get();
        foreach ($sets as $set) {
            $published = $set->publishedVersion();
            if (! $published || isset($published->payload['locales'][self::LOCALE]['channels'])) {
                continue;
            }
            $localized = self::localizeChannels($published->payload['channels'] ?? [], $map);
            if ($localized === null) {
                continue;
            }
            $payload = $published->payload;
            $payload['locales'][self::LOCALE]['channels'] = $localized;

            $draft = $service->createDraft($set, $payload, null, 'i18n: Indonesian wording (locales.id)');
            $service->publish($draft, null, fn (array $p) => $validator->validate($set->code, $p));
        }
    }

    /** The channels with every subject/body translated, or null when any text has no dataset translation. */
    public static function localizeChannels(array $channels, array $map): ?array
    {
        if ($channels === []) {
            return null;
        }
        $localized = [];
        foreach ($channels as $channel => $content) {
            foreach (['subject', 'body'] as $field) {
                if (! isset($content[$field])) {
                    continue;
                }
                $translation = $map[trim((string) $content[$field])] ?? null;
                if ($translation === null) {
                    return null;
                }
                $localized[$channel][$field] = $translation;
            }
        }

        return $localized;
    }

    /** notifications.* English → target text; an English text with two different translations is left out. */
    public static function translations(string $locale): array
    {
        $english = (array) Lang::get('catalog', [], 'en', false);
        $target = (array) Lang::get('catalog', [], $locale, false);
        $byText = [];
        foreach ($english as $key => $text) {
            if (str_starts_with((string) $key, 'notifications.') && is_string($text) && is_string($target[$key] ?? null) && $target[$key] !== '') {
                $byText[$text][$target[$key]] = true;
            }
        }

        return array_map(fn (array $t) => (string) array_key_first($t), array_filter($byText, fn (array $t) => count($t) === 1));
    }
}
