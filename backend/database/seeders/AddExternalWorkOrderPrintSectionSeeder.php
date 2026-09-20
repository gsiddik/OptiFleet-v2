<?php

namespace Database\Seeders;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\TemplateValidator;
use Illuminate\Database\Seeder;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Section 3: printing an External Work Order must show its
 * finalized Findings and revision number, and must NOT show internal-only
 * content (Jobs). This publishes a NEW version of the platform's default
 * 'work_order' print template — never edits the already-published one in
 * place — wrapping the existing Jobs table in {{^is_external}} and adding
 * an {{#is_external}} Findings section, using
 * DocumentTemplateContextBuilder::forWorkOrder()'s new `is_external`/
 * `findings`/`work_order.revision` context fields. Idempotent: does
 * nothing once the published template already contains the marker.
 */
class AddExternalWorkOrderPrintSectionSeeder extends Seeder
{
    private const MARKER = 'is_external';

    public function run(): void
    {
        $service = app(ConfigurationService::class);
        $validator = app(TemplateValidator::class);

        $set = $service->findOrCreateSet(null, 'TEMPLATE', 'work_order', 'TENANT', null, 'Work Order Template', true);
        $published = $set->publishedVersion();

        if (! $published) {
            return;
        }

        $html = $published->payload['html'] ?? '';
        if (str_contains($html, self::MARKER)) {
            return;
        }

        // Locate the Jobs table by its {{#jobs}} loop marker rather than a hand-copied literal —
        // robust to whatever exact indentation the published HTML actually has.
        if (! preg_match('/<table\b[^>]*>.*?\{\{#jobs\}\}.*?<\/table>/s', $html, $match)) {
            // The published version no longer has a recognizable Jobs table (e.g. a tenant
            // customized it) — do nothing rather than risk corrupting a customized template.
            return;
        }
        $jobsTable = $match[0];

        $findingsBlock = <<<'HTML'
            <p>Revision: {{work_order.revision}}</p>
            <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
              <tr><th>Severity</th><th>Finding</th><th>Status</th></tr>
              {{#findings}}<tr><td>{{severity}}</td><td>{{description}}</td><td>{{status}}</td></tr>{{/findings}}
            </table>
            HTML;

        $externalSection = "{{^is_external}}\n{$jobsTable}\n{{/is_external}}\n{{#is_external}}\n{$findingsBlock}\n{{/is_external}}";

        $newHtml = str_replace($jobsTable, $externalSection, $html);

        $draft = $service->createDraft($set, ['html' => $newHtml], null, 'Add External Work Order Findings-only print section (revision + Findings, Jobs hidden)');
        $service->publish($draft, null, fn (array $payload) => $validator->validate('work_order', $payload['html']));
    }
}
