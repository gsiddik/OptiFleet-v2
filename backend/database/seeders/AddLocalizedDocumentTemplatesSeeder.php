<?php

namespace Database\Seeders;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\TemplateValidator;
use App\Domain\Configuration\Services\TemplateVariableRegistry;
use App\Domain\DocumentGeneration\Support\DocumentTemplateLocalizer;
use Illuminate\Database\Seeder;

/**
 * i18n (documents): publishes a NEW version of every platform default print template that carries an
 * Indonesian body (`payload.locales.id.html`, from the documents.* dataset rows) beside its English one,
 * and prints statuses through their display label (`….status_label`) instead of the raw code.
 *
 * - Published history is never edited; tenant templates are never touched (platform defaults only).
 * - A template whose English text is not fully covered by the dataset is left as it is (no half-translated
 *   body); `generations` already printed keep their own pinned version (reprint unchanged).
 * - Idempotent: does nothing once the published version has `locales.id`.
 */
class AddLocalizedDocumentTemplatesSeeder extends Seeder
{
    public const LOCALE = 'id';

    public function run(): void
    {
        $service = app(ConfigurationService::class);
        $validator = app(TemplateValidator::class);

        foreach (app(TemplateVariableRegistry::class)->documentTypes() as $documentType) {
            // The platform default set only (tenant_id null); a missing set is not created here.
            $set = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')
                ->where('type', 'TEMPLATE')->where('code', $documentType)->where('scope_type', 'TENANT')->whereNull('scope_resource_id')->first();
            $published = $set?->publishedVersion();
            if (! $published || isset($published->payload['locales'][self::LOCALE]['html'])) {
                continue;
            }
            // Localize the published wording (the dataset rows hold it as published), then switch both bodies
            // to status labels.
            $english = (string) ($published->payload['html'] ?? '');
            if ($english === '' || DocumentTemplateLocalizer::untranslated($english, self::LOCALE) !== []) {
                continue;
            }
            $payload = $published->payload;
            $payload['html'] = self::withStatusLabels($english);
            $payload['locales'][self::LOCALE]['html'] = self::withStatusLabels(DocumentTemplateLocalizer::localize($english, self::LOCALE));

            $draft = $service->createDraft($set, $payload, null, 'i18n: Indonesian body (locales.id) and localized status labels');
            $service->publish($draft, null, function (array $payload) use ($validator, $documentType) {
                $validator->validate($documentType, $payload['html']);
                $validator->validate($documentType, $payload['locales'][self::LOCALE]['html']);
            });
        }
    }

    /** {{x.status}} / {{status}} → the display label variable (the code stays available to custom templates). */
    public static function withStatusLabels(string $html): string
    {
        return preg_replace('/\{\{(\s*(?:[\w]+\.)*status)(\s*)\}\}/', '{{$1_label$2}}', $html) ?? $html;
    }
}
