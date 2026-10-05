<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Configuration\Services\TemplateDocumentCompiler;
use App\Domain\Configuration\Services\TemplateRenderer;
use App\Domain\Configuration\Services\TemplateValidationException;
use App\Domain\Configuration\Services\TemplateVariableRegistry;
use Database\Seeders\ConfigurationDefaultsSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Configuration → Document Template visual editor contract: the editor document (JSON) is
 * compiled server-side into allowlisted HTML (the client's HTML is never trusted), variables /
 * repeating blocks keep the existing template grammar, every default template survives the
 * sanitizer losslessly, and unknown or out-of-scope variables are rejected.
 */
class DocumentTemplateEditorTest extends TestCase
{
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TPL-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['configuration.view', 'document_template.manage', 'document_template.publish']);

        return ['tenant' => $tenant, 'headers' => $this->authHeaders($token)];
    }

    /** A representative editor document: heading, formatting, variables, a Jobs table and a Findings block. */
    private function editorDocument(): array
    {
        $text = fn (string $v) => ['t' => 'text', 'v' => $v];
        $var = fn (string $p) => ['t' => 'var', 'path' => $p];
        $el = fn (string $tag, array $children, array $attrs = []) => ['t' => 'el', 'tag' => $tag, 'attrs' => $attrs, 'children' => $children];

        return ['version' => 1, 'nodes' => [
            $el('h2', [$text('Work Order '), $var('work_order.number')], ['style' => 'text-align:center']),
            $el('p', [$el('strong', [$text('Vehicle: ')]), $var('vehicle.registration_number'), $text(' — '), $el('em', [$var('vehicle.brand')])]),
            $el('table', [$el('tbody', [
                $el('tr', [$el('th', [$text('Job')]), $el('th', [$text('Hours')])]),
                ['t' => 'section', 'name' => 'jobs', 'mode' => 'element', 'children' => [$el('tr', [$el('td', [$var('description')]), $el('td', [$var('estimated_hours')])])]],
            ])], ['border' => '1', 'style' => 'width:100%;border-collapse:collapse']),
            ['t' => 'section', 'name' => 'findings', 'mode' => 'block', 'children' => [$el('p', [$var('severity'), $text(': '), $var('description')])]],
            $el('ul', [$el('li', [$text('Typed {{work_order.number}} stays text')])]),
        ]];
    }

    public function test_editor_document_compiles_to_renderable_template_and_round_trips(): void
    {
        $s = $this->scenario();
        $editor = $this->editorDocument();
        $draft = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'TEMPLATE', 'code' => 'work_order', 'name' => 'Our Work Order', 'payload' => ['editor' => $editor, 'html' => '<script>ignored</script>']], $s['headers'])->assertStatus(201);
        $html = $draft->json('data.payload.html');
        $this->assertSame($editor, $draft->json('data.payload.editor'), 'the editor state is stored as-is');
        $this->assertStringNotContainsString('<script', $html, 'client HTML is ignored when an editor document is given');
        $this->assertStringContainsString('{{#jobs}}<tr><td>{{description}}</td><td>{{estimated_hours}}</td></tr>{{/jobs}}', $html);
        $this->assertStringContainsString('{{#findings}}<p>{{severity}}: {{description}}</p>{{/findings}}', $html);
        $this->assertStringContainsString('Typed { {work_order.number} } stays text', $html, 'typed braces never become a variable');

        // Publishable and rendered by the existing engine with several jobs / findings.
        $this->postJson('/api/v1/app/configuration/versions/'.$draft->json('data.id').'/publish', [], $s['headers'])->assertOk();
        $rendered = app(TemplateRenderer::class)->render($html, [
            'work_order' => ['number' => 'WO/1'], 'vehicle' => ['registration_number' => 'B 1 AB', 'brand' => 'Hino'],
            'jobs' => [['description' => 'Brake', 'estimated_hours' => '2'], ['description' => 'Oil', 'estimated_hours' => '1']],
            'findings' => [['severity' => 'HIGH', 'description' => 'Leak'], ['severity' => 'LOW', 'description' => 'Scratch']],
        ]);
        foreach (['WO/1', 'B 1 AB', '<em>Hino</em>', '<td>Brake</td>', '<td>Oil</td>', 'HIGH: Leak', 'LOW: Scratch'] as $expected) {
            $this->assertStringContainsString($expected, $rendered);
        }

        // Preview of an unsaved editor document.
        $preview = $this->postJson('/api/v1/app/configuration/preview', ['type' => 'TEMPLATE', 'code' => 'work_order', 'editor' => $editor['nodes']], $s['headers'])->assertOk()->json('data.html');
        $this->assertStringContainsString('Description 1', $preview);
        $this->assertStringContainsString('Description 2', $preview);
    }

    public function test_sanitizer_drops_unsafe_markup_and_keeps_safe_layout(): void
    {
        $compiler = app(TemplateDocumentCompiler::class);
        $dirty = '<div style="display:flex;background:url(javascript:alert(1));color:red" onclick="x()"><script>alert(1)</script>'
            .'<a href="javascript:alert(2)">link</a><img src=x onerror=alert(3)><iframe src="//x"></iframe>'
            .'<p style="behavior:expression(alert(4));text-align:right">{{vehicle.registration_number}}</p></div>';
        $clean = $compiler->sanitize($dirty);
        $this->assertSame('<div style="display:flex;color:red">link<p style="text-align:right">{{vehicle.registration_number}}</p></div>', $clean);

        // Editor documents go through the same allowlist; invalid variable names are refused.
        $this->assertSame('<p>x</p>', $compiler->build([['t' => 'el', 'tag' => 'p', 'attrs' => ['onmouseover' => 'x()'], 'children' => [['t' => 'text', 'v' => 'x']]]]));
        $this->expectException(TemplateValidationException::class);
        $compiler->build([['t' => 'var', 'path' => 'vehicle.registration_number}}{{#x']]);
    }

    public function test_unknown_and_out_of_scope_variables_are_rejected_on_publish(): void
    {
        $s = $this->scenario();
        $outOfScope = ['version' => 1, 'nodes' => [
            ['t' => 'section', 'name' => 'jobs', 'mode' => 'block', 'children' => [['t' => 'var', 'path' => 'severity']]], // a findings field inside Jobs
        ]];
        $id = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'TEMPLATE', 'code' => 'work_order', 'name' => 'Bad', 'payload' => ['editor' => $outOfScope]], $s['headers'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$id}/publish", [], $s['headers'])->assertStatus(422)->assertJsonFragment(['message' => 'Template references unknown variable(s): severity']);

        $unknown = ['version' => 1, 'nodes' => [['t' => 'var', 'path' => 'vehicle.secret_field']]];
        $id = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'TEMPLATE', 'code' => 'work_order', 'name' => 'Bad', 'payload' => ['editor' => $unknown]], $s['headers'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$id}/publish", [], $s['headers'])->assertStatus(422);
        // A template must be for a document type the system prints.
        $this->postJson('/api/v1/app/configuration/versions', ['type' => 'TEMPLATE', 'code' => 'nope', 'name' => 'X', 'payload' => ['html' => '<p>x</p>']], $s['headers'])->assertStatus(422);
    }

    public function test_every_default_template_survives_the_sanitizer_losslessly(): void
    {
        $this->seed(ConfigurationDefaultsSeeder::class);
        $compiler = app(TemplateDocumentCompiler::class);
        $renderer = app(TemplateRenderer::class);
        $registry = app(TemplateVariableRegistry::class);
        $normalize = fn (string $html) => preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>', '</div>'], "\n", $html))));

        $sets = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'TEMPLATE')->get();
        $this->assertGreaterThanOrEqual(15, $sets->count());
        foreach ($sets as $set) {
            $original = $set->publishedVersion()->payload['html'];
            $sanitized = $compiler->sanitize($original);
            $context = $registry->sampleContext($set->code);
            $this->assertSame($normalize($renderer->render($original, $context)), $normalize($renderer->render($sanitized, $context)), "{$set->code}: same text");
            $this->assertSame(substr_count($original, '{{'), substr_count($sanitized, '{{'), "{$set->code}: every variable / block kept");
            $this->assertSame(substr_count($original, '<tr'), substr_count($sanitized, '<tr'), "{$set->code}: table rows kept");
            $this->assertSame(substr_count($original, 'style="'), substr_count($sanitized, 'style="'), "{$set->code}: styles kept");
            $this->assertSame($sanitized, $compiler->sanitize($sanitized), "{$set->code}: sanitizing is stable");
        }
    }

    public function test_return_to_default_template_and_rendering_uses_the_default_again(): void
    {
        $s = $this->scenario();
        $service = app(ConfigurationService::class);
        $default = $service->findOrCreateSet(null, 'TEMPLATE', 'goods_receipt', 'TENANT', null, 'Goods Receipt Template', true);
        if (! $default->publishedVersion()) {
            $service->publish($service->createDraft($default, ['html' => '<p>DEFAULT {{goods_receipt.number}}</p>'], null), null);
        }
        $id = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'TEMPLATE', 'code' => 'goods_receipt', 'name' => 'Ours', 'payload' => ['editor' => ['version' => 1, 'nodes' => [
            ['t' => 'el', 'tag' => 'p', 'attrs' => [], 'children' => [['t' => 'text', 'v' => 'CUSTOM '], ['t' => 'var', 'path' => 'goods_receipt.number']]],
        ]]]], $s['headers'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$id}/publish", [], $s['headers'])->assertOk();
        $render = fn () => app(DocumentTemplateRenderService::class)->render('goods_receipt', ['goods_receipt' => ['number' => 'GR/9']], $s['tenant']->id)['html'];
        $this->assertStringContainsString('CUSTOM GR/9', $render());

        $set = ConfigurationSet::query()->where('tenant_id', $s['tenant']->id)->where('code', 'goods_receipt')->firstOrFail();
        $this->postJson("/api/v1/app/configuration/sets/{$set->id}/restore-default", [], $s['headers'])->assertOk();
        $this->assertStringNotContainsString('CUSTOM', $render());
    }
}
