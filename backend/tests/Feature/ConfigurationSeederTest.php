<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Configuration\Services\DocumentTypeRegistry;
use App\Domain\Configuration\Services\NumberingFormatValidator;
use App\Domain\Configuration\Services\TemplateRenderer;
use App\Domain\Configuration\Services\TemplateVariableRegistry;
use App\Domain\Notification\Models\NotificationRule;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigurationDefaultsSeeder;
use Database\Seeders\ConfigurationShowcaseSeeder;
use Database\Seeders\NotificationDefaultsSeeder;
use Tests\TestCase;

/**
 * Configuration seeders: the platform defaults cover every document type that supports
 * numbering and open in the numbering builder (known tokens only), and the demo showcase
 * (BETA) seeds the representative numbering formats, a visual-editor template and a
 * notification rule — valid, idempotent, and never overwriting what exists.
 */
class ConfigurationSeederTest extends TestCase
{
    public function test_platform_numbering_defaults_cover_the_registry_and_fit_the_builder(): void
    {
        $this->seed(ConfigurationDefaultsSeeder::class);
        $defaults = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'NUMBERING')->get()->keyBy('code');
        foreach (app(DocumentTypeRegistry::class)->forNumbering() as $type) {
            $set = $defaults[$type['key']] ?? null;
            $this->assertNotNull($set, "{$type['key']}: has a System Default");
            $payload = $set->publishedVersion()->payload;
            app(NumberingFormatValidator::class)->validate($payload);
            preg_match_all('/\{([A-Z]+)(:\d+)?\}/', $payload['format'], $m);
            foreach ($m[1] as $token) {
                $this->assertContains($token, [...NumberingFormatValidator::ALLOWED_TOKENS, 'SEQ'], "{$type['key']}: {$token} is a builder token");
            }
        }
    }

    public function test_showcase_seeds_representative_configurations_idempotently(): void
    {
        $this->seed(ConfigurationDefaultsSeeder::class);
        $this->seed(NotificationDefaultsSeeder::class);
        $tenant = $this->makeTenant(['code' => 'BETA']);
        $this->seed(ConfigurationShowcaseSeeder::class);

        $sets = ConfigurationSet::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->keyBy(fn ($s) => $s->type.':'.$s->code);
        $this->assertCount(6, $sets);
        $at = CarbonImmutable::parse('2026-10-05');
        $numbering = app(DocumentNumberingService::class);
        $preview = fn (string $code) => $numbering->preview($sets["NUMBERING:{$code}"]->versions()->first()->payload, $code, $tenant->id, null, null, null, 1, $at);

        $this->assertSame('RPO-ALP/WSBDG/10/2026/000001', $preview('purchase_order'), 'owner example');
        $this->assertNotNull($sets['NUMBERING:purchase_order']->publishedVersion(), 'RPO example is the active custom configuration');
        $this->assertSame('RFQ/2026/1', $preview('rfq'));
        $this->assertMatchesRegularExpression('#^.+-26-10-0001$#', $preview('maintenance_request'));
        $this->assertMatchesRegularExpression('#^.+/OCTOBER/2026/00001$#', $preview('goods_receipt'));
        $this->assertSame('DOCSTORE-TRF-202610/TRF0001', $preview('stock_transfer'), 'literal kept, token repeated');
        foreach (['rfq', 'maintenance_request', 'goods_receipt', 'stock_transfer'] as $code) {
            $this->assertNull($sets["NUMBERING:{$code}"]->publishedVersion(), "{$code}: draft only");
        }

        // The template: built by the visual editor's compiler, renders variables, jobs and findings.
        $template = $sets['TEMPLATE:work_order']->versions()->first()->payload;
        $this->assertSame(1, $template['editor']['version']);
        $html = $template['html'];
        foreach (['{{#jobs}}<tr>', '{{/jobs}}', '{{#findings}}', '<strong>', '<em>', '<u>', '<table', 'text-align:center'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        $rendered = app(TemplateRenderer::class)->render($html, app(TemplateVariableRegistry::class)->sampleContext('work_order'));
        $this->assertStringContainsString('Description 1', $rendered);
        $this->assertStringContainsString('Description 2', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);

        $rule = NotificationRule::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('inventory.low_stock', $rule->event_code);
        $this->assertSame(240, $rule->escalation['after_minutes']);

        // Re-running changes nothing.
        $versions = ConfigurationVersion::query()->count();
        $this->seed(ConfigurationShowcaseSeeder::class);
        $this->seed(ConfigurationDefaultsSeeder::class);
        $this->assertSame($versions, ConfigurationVersion::query()->count());
        $this->assertSame(1, NotificationRule::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertCount(6, ConfigurationSet::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get());
    }
}
