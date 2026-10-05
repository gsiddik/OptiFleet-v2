<?php

namespace Database\Seeders;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\NumberingFormatValidator;
use App\Domain\Configuration\Services\TemplateDocumentCompiler;
use App\Domain\Configuration\Services\TemplateValidator;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Notification\Services\NotificationRuleService;
use Illuminate\Database\Seeder;

/**
 * Demo layer: representative Custom Configurations for the BETA demo tenant
 * (beta.admin@optifleet.test), so the Document Numbering builder, the Document Template
 * visual editor and the Notification forms open on realistic examples — the owner's RPO
 * example, plain / branch / warehouse formats, a literal that contains a token name and a
 * repeated token, a template with variables, Jobs table, Findings block and formatting, and a
 * notification rule with a condition and an escalation.
 *
 * Only the RPO numbering is published (BETA's new Purchase Orders use it; Return to System
 * Default reverts it); the others are drafts. ALPHA keeps the System Defaults. Idempotent: a
 * configuration that already has a version, or a rule with the same name, is left as it is.
 */
class ConfigurationShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->where('code', 'BETA')->first();
        if (! $tenant) {
            return;
        }
        $service = app(ConfigurationService::class);
        $numberingValidator = app(NumberingFormatValidator::class);

        $numbering = [
            // Owner example: RPO-ALP/WSBDG/10/2026/000001 (initials typed, 6 digits).
            'purchase_order' => ['RPO example (published)', true, [
                'format' => 'RPO-{TENANT}/{WORKSHOP}/{MM}/{YYYY}/{SEQ:6}', 'doc_code' => 'PO', 'reset_rule' => 'YEARLY',
                'tenant_initial' => 'ALP', 'workshop_initial' => 'WSBDG',
            ]],
            'rfq' => ['Plain DOC/YYYY/SEQ', false, ['format' => '{DOC}/{YYYY}/{SEQ:1}', 'doc_code' => 'RFQ', 'reset_rule' => 'YEARLY']],
            'maintenance_request' => ['Branch and month', false, ['format' => '{BRANCH}-{YY}-{MM}-{SEQ:4}', 'doc_code' => 'MR', 'reset_rule' => 'MONTHLY']],
            'goods_receipt' => ['Warehouse and month name', false, ['format' => '{WAREHOUSE}/{MMMM}/{YYYY}/{SEQ:5}', 'doc_code' => 'GR', 'reset_rule' => 'YEARLY']],
            // "DOCSTORE" is literal text (not the DOC token); DOC is used twice.
            'stock_transfer' => ['Literal text and a repeated token', false, ['format' => 'DOCSTORE-{DOC}-{YYYY}{MM}/{DOC}{SEQ:4}', 'doc_code' => 'TRF', 'reset_rule' => 'MONTHLY']],
        ];
        foreach ($numbering as $code => [$name, $publish, $payload]) {
            $numberingValidator->validate($payload);
            $this->seedCustom($service, $tenant->id, 'NUMBERING', $code, $name, $payload, $publish);
        }

        $editor = $this->workOrderTemplate();
        $html = app(TemplateDocumentCompiler::class)->build($editor['nodes']);
        app(TemplateValidator::class)->validate('work_order', $html);
        $this->seedCustom($service, $tenant->id, 'TEMPLATE', 'work_order', 'Work Order with findings', ['html' => $html, 'editor' => $editor], false);

        if (! NotificationRule::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Low stock → warehouse team')->exists()) {
            app(NotificationRuleService::class)->create($tenant->id, 'inventory.low_stock', 'Low stock → warehouse team',
                [['type' => 'WAREHOUSE_PIC'], ['type' => 'PERMISSION', 'identifier' => 'inventory.view']],
                ['IN_APP'],
                ['operator' => 'AND', 'rules' => [['field' => 'stock.available', 'op' => '<=', 'value' => 5]]],
                ['after_minutes' => 240, 'recipient_rules' => [['type' => 'ROLE', 'identifier' => 'Warehouse Manager']]],
            );
        }
    }

    private function seedCustom(ConfigurationService $service, string $tenantId, string $type, string $code, string $name, array $payload, bool $publish): void
    {
        $set = $service->findOrCreateSet($tenantId, $type, $code, 'TENANT', null, $name);
        if ($set->versions()->exists()) {
            return; // already seeded (or changed by the tenant) — never overwrite
        }
        $draft = $service->createDraft($set, $payload, null, 'Demo example');
        if ($publish) {
            $service->publish($draft, null);
        }
    }

    /** The visual editor document: heading, formatting, variables, a Jobs table and a Findings block. */
    private function workOrderTemplate(): array
    {
        $text = fn (string $v) => ['t' => 'text', 'v' => $v];
        $var = fn (string $p) => ['t' => 'var', 'path' => $p];
        $el = fn (string $tag, array $children, array $attrs = []) => ['t' => 'el', 'tag' => $tag, 'attrs' => $attrs, 'children' => $children];
        $cell = 'border:1px solid #d1d5db;padding:6px';

        return ['version' => 1, 'nodes' => [
            $el('h1', [$text('Work Order '), $var('work_order.number')], ['style' => 'text-align:center']),
            $el('p', [$el('strong', [$text('Vehicle: ')]), $var('vehicle.registration_number'), $text(' — '), $el('em', [$var('vehicle.brand'), $text(' '), $var('vehicle.model')])]),
            $el('p', [$el('strong', [$text('Workshop: ')]), $var('workshop.name'), $text(' · '), $el('u', [$text('Priority: '), $var('work_order.priority')])]),
            $el('h3', [$text('Jobs')]),
            $el('table', [$el('tbody', [
                $el('tr', [$el('th', [$text('Job')], ['style' => $cell]), $el('th', [$text('Status')], ['style' => $cell]), $el('th', [$text('Estimated Hours')], ['style' => $cell])], ['style' => 'background:#f3f4f6']),
                ['t' => 'section', 'name' => 'jobs', 'mode' => 'element', 'children' => [
                    $el('tr', [$el('td', [$var('description')], ['style' => $cell]), $el('td', [$var('status')], ['style' => $cell]), $el('td', [$var('estimated_hours')], ['style' => $cell.';text-align:right'])]),
                ]],
            ])], ['style' => 'width:100%;border-collapse:collapse']),
            $el('h3', [$text('Findings')]),
            ['t' => 'section', 'name' => 'findings', 'mode' => 'block', 'children' => [
                $el('ul', [$el('li', [$el('strong', [$var('severity')]), $text(': '), $var('description'), $text(' ('), $var('status'), $text(')')])]),
            ]],
            $el('p', [$text('Checked by: ____________________')], ['style' => 'margin-top:24px']),
        ]];
    }
}
