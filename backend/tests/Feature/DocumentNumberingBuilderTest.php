<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Configuration\Services\DocumentTypeRegistry;
use App\Domain\Configuration\Services\TemplateVariableRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Configuration → Document Numbering builder contract: every token, literals that look like
 * tokens, repeated tokens, SEQ without padding, Initials (owner decision: optional override of
 * the entity code), the shared Document Type registry, the real number following the preview,
 * and Return to System Default.
 */
class DocumentNumberingBuilderTest extends TestCase
{
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'ALPHA'.Str::upper(Str::random(3))]);
        $branch = $this->makeBranch($tenant, ['code' => 'BDG'.Str::upper(Str::random(3))]);
        $workshop = $this->makeWorkshop($tenant, $branch, ['code' => 'WS'.Str::upper(Str::random(3))]);
        [, $token] = $this->makeTenantUser($tenant, ['configuration.view', 'numbering.manage', 'numbering.publish', 'document_template.manage', 'document_template.publish']);

        return ['tenant' => $tenant, 'branch' => $branch, 'workshop' => $workshop, 'headers' => $this->authHeaders($token)];
    }

    private function preview(array $s, array $payload, string $code = 'purchase_order', string $date = '2026-10-15')
    {
        return $this->postJson('/api/v1/app/configuration/preview', ['type' => 'NUMBERING', 'code' => $code, 'payload' => $payload, 'sample_date' => $date], $s['headers']);
    }

    public function test_owner_example_preview_and_every_token(): void
    {
        $s = $this->scenario();
        // RPO- is literal text typed by the user, not the DOC token.
        $this->assertSame('RPO-ALP/WSBDG/10/2026/000001', $this->preview($s, [
            'format' => 'RPO-{TENANT}/{WORKSHOP}/{MM}/{YYYY}/{SEQ:6}', 'tenant_initial' => 'ALP', 'workshop_initial' => 'WSBDG',
        ])->assertOk()->json('data.preview'));

        $all = '{DOC}|{TENANT}|{BRANCH}|{WORKSHOP}|{WAREHOUSE}|{YYYY}|{YY}|{MMM}|{MMMM}|{MM}|{DD}|{SEQ:1}|{SEQ:6}';
        $this->assertSame('PO|ALP|BR1|WS1|WH1|2026|26|OCT|OCTOBER|10|15|1|000001', $this->preview($s, [
            'format' => $all, 'doc_code' => 'PO', 'tenant_initial' => 'ALP', 'branch_initial' => 'BR1', 'workshop_initial' => 'WS1', 'warehouse_initial' => 'WH1',
        ])->json('data.preview'));

        // Repeated tokens and literals that contain token names stay literal.
        $this->assertSame('DOCSTORE-ALP/ALP/2026/7', $this->preview($s, [
            'format' => 'DOCSTORE-{TENANT}/{TENANT}/{YYYY}/{SEQ:1}', 'tenant_initial' => 'ALP', 'sequence_start' => 7,
        ])->json('data.preview'));
        // Other representative cases.
        $this->assertSame('PO/2026/1', $this->preview($s, ['format' => '{DOC}/{YYYY}/{SEQ:1}', 'doc_code' => 'PO'])->json('data.preview'));
        $this->assertSame('BDG-26-10-0001', $this->preview($s, ['format' => '{BRANCH}-{YY}-{MM}-{SEQ:4}', 'branch_initial' => 'BDG'])->json('data.preview'));
        $this->assertSame('WHJKT/OCTOBER/2026/00001', $this->preview($s, ['format' => '{WAREHOUSE}/{MMMM}/{YYYY}/{SEQ:5}', 'warehouse_initial' => 'WHJKT'])->json('data.preview'));
        // Without an Initial the preview shows the tenant's own code / a sample branch code.
        $this->assertSame($s['tenant']->code.'/'.$s['branch']->code.'/000001', $this->preview($s, ['format' => '{TENANT}/{BRANCH}/{SEQ:6}'])->json('data.preview'));
    }

    public function test_validation_rejects_bad_formats(): void
    {
        $s = $this->scenario();
        foreach ([
            ['format' => '{DOC}/{YYYY}'],                         // no sequence
            ['format' => '{DOC}/{FOO}/{SEQ:6}'],                  // unknown token
            ['format' => '{DOC}/{SEQ:0}'],                        // digits out of range
            ['format' => '{DOC}/{SEQ:13}'],
            ['format' => '{YYYY:4}/{SEQ:3}'],                     // only SEQ takes a number
            ['format' => '{DOC/{SEQ:3}'],                         // malformed
            ['format' => '{TENANT}/{SEQ:3}', 'tenant_initial' => 'A{B}'],
            ['format' => ''],
        ] as $payload) {
            $this->preview($s, $payload)->assertStatus(422);
        }
        // A new configuration must be for a document type the system numbers.
        $this->postJson('/api/v1/app/configuration/versions', ['type' => 'NUMBERING', 'code' => 'not_a_document', 'name' => 'X', 'payload' => ['format' => '{SEQ:3}']], $s['headers'])->assertStatus(422);
    }

    public function test_initial_overrides_the_entity_code_and_empty_keeps_it(): void
    {
        $s = $this->scenario();
        $service = app(ConfigurationService::class);
        $set = $service->findOrCreateSet($s['tenant']->id, 'NUMBERING', 'work_order', 'TENANT', null, 'WO');
        $service->publish($service->createDraft($set, ['format' => 'WO/{BRANCH}/{WORKSHOP}/{SEQ:3}', 'workshop_initial' => 'WSBDG'], null), null);

        $number = DB::transaction(fn () => app(DocumentNumberingService::class)->generate('work_order', $s['tenant']->id, $s['branch']->id, $s['workshop']->id));
        $this->assertSame("WO/{$s['branch']->code}/WSBDG/001", $number['document_number']);
    }

    public function test_saved_builder_configuration_generates_what_the_preview_shows_and_return_to_default(): void
    {
        Carbon::setTestNow('2026-10-15 09:00:00');
        $s = $this->scenario();
        $service = app(ConfigurationService::class);
        $default = $service->findOrCreateSet(null, 'NUMBERING', 'purchase_order', 'TENANT', null, 'Purchase Order Numbering', true);
        if (! $default->publishedVersion()) {
            $service->publish($service->createDraft($default, ['format' => 'PO/{YYYY}/{SEQ:6}', 'doc_code' => 'PO', 'reset_rule' => 'YEARLY'], null), null);
        }

        // System Default → New Draft (the tenant's own configuration of the same document type).
        $payload = ['format' => 'RPO-{TENANT}/{WORKSHOP}/{MM}/{YYYY}/{SEQ:6}', 'tenant_initial' => 'ALP', 'workshop_initial' => 'WSBDG', 'reset_rule' => 'YEARLY'];
        $draft = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'NUMBERING', 'code' => 'purchase_order', 'name' => 'Our PO numbers', 'payload' => $payload], $s['headers'])->assertStatus(201);
        $this->assertSame($payload, $draft->json('data.payload'), 'the builder configuration is stored as-is (reloadable)');
        $preview = $this->preview($s, $payload)->json('data.preview');
        $this->postJson('/api/v1/app/configuration/versions/'.$draft->json('data.id').'/publish', [], $s['headers'])->assertOk();

        $numbering = app(DocumentNumberingService::class);
        $first = DB::transaction(fn () => $numbering->generate('purchase_order', $s['tenant']->id));
        $this->assertSame($preview, $first['document_number']);
        $this->assertSame('RPO-ALP/WSBDG/10/2026/000002', DB::transaction(fn () => $numbering->generate('purchase_order', $s['tenant']->id))['document_number']);

        // Return to System Default: the custom one is archived (kept), the default numbers again.
        $set = collect($this->getJson('/api/v1/app/configuration/sets?type=NUMBERING', $s['headers'])->json('data'))
            ->first(fn ($row) => $row['code'] === 'purchase_order' && $row['tenant_id'] === $s['tenant']->id);
        $this->postJson("/api/v1/app/configuration/sets/{$set['id']}/restore-default", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->assertMatchesRegularExpression('#^PO/2026/\d{6}$#', DB::transaction(fn () => $numbering->generate('purchase_order', $s['tenant']->id))['document_number']);
        $this->postJson("/api/v1/app/configuration/sets/{$set['id']}/restore-default", [], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/configuration/sets/{$default->id}/restore-default", [], $s['headers'])->assertStatus(404);
        Carbon::setTestNow();
    }

    public function test_metadata_comes_from_the_shared_document_type_registry(): void
    {
        $s = $this->scenario();
        $numbering = $this->getJson('/api/v1/app/configuration/metadata?type=NUMBERING', $s['headers'])->assertOk()->json('data');
        $keys = array_column($numbering['document_types'], 'key');
        foreach (['work_order', 'maintenance_request', 'purchase_order', 'goods_receipt', 'purchase_return', 'rfq', 'product_sku'] as $type) {
            $this->assertContains($type, $keys);
        }
        $this->assertSame(['DOC', 'TENANT', 'BRANCH', 'WORKSHOP', 'WAREHOUSE', 'YYYY', 'YY', 'MMMM', 'MMM', 'MM', 'DD', 'SEQ', 'SEQ:N', 'ITEMTYPE', 'CG'], array_column($numbering['token_definitions'], 'token'));

        $template = $this->getJson('/api/v1/app/configuration/metadata?type=TEMPLATE&code=work_order', $s['headers'])->assertOk()->json('data');
        $this->assertContains('work_order', array_column($template['document_type_options'], 'key'));
        $this->assertSame(['jobs', 'findings'], array_column($template['catalog']['blocks'], 'name'));
        $registration = collect($template['catalog']['variables'])->firstWhere('key', 'vehicle.registration_number');
        $this->assertSame(['Registration Number', 'Vehicle', 'Text'], [$registration['label'], $registration['category'], $registration['type']]);

        // Every template / seeded numbering type is in the registry.
        $registry = app(DocumentTypeRegistry::class);
        foreach (app(TemplateVariableRegistry::class)->documentTypes() as $type) {
            $this->assertTrue($registry->supportsTemplate($type), $type);
        }
        foreach (['work_order', 'maintenance_request', 'vehicle_transfer', 'stock_transfer', 'purchase_request', 'rfq', 'purchase_order', 'goods_receipt', 'warranty_claim', 'maintenance_memo', 'product_item', 'product_sku', 'part_return', 'purchase_return'] as $type) {
            $this->assertTrue($registry->supportsNumbering($type), $type);
        }
    }
}
