<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Configuration\Services\DocumentTemplateService;
use App\Domain\Configuration\Services\TemplateValidationException;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentTemplateTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'TPL-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);

        return [$tenant, $branch, $workshop, $vehicle];
    }

    public function test_template_draft_publish_lifecycle_preserves_history(): void
    {
        $tenant = $this->makeTenant(['code' => 'TPL1-'.Str::random(4)]);
        $service = app(DocumentTemplateService::class);

        $set = $service->findOrCreateSet($tenant->id, 'work_order', 'TENANT', null, 'WO Template');
        $v1 = $service->publish($service->createDraft($set, ['html' => '{{document_number}}'], null), 'work_order', null);

        $this->assertSame('PUBLISHED', $v1->fresh()->status);

        $v2Draft = $service->createDraft($set, ['html' => '{{document_number}} v2'], null);
        $v2 = $service->publish($v2Draft, 'work_order', null);

        $this->assertSame('ARCHIVED', $v1->fresh()->status);
        $this->assertSame('PUBLISHED', $v2->fresh()->status);
        $this->assertSame('{{document_number}}', $v1->fresh()->payload['html']); // history untouched
    }

    public function test_publish_rejects_template_with_unknown_variable(): void
    {
        $tenant = $this->makeTenant(['code' => 'TPL2-'.Str::random(4)]);
        $service = app(DocumentTemplateService::class);
        $set = $service->findOrCreateSet($tenant->id, 'work_order', 'TENANT', null, 'WO Template');
        $draft = $service->createDraft($set, ['html' => 'Total: {{secret.field}}'], null);

        $this->expectException(TemplateValidationException::class);
        $service->publish($draft, 'work_order', null);
    }

    public function test_publish_rejects_template_referencing_unknown_repeating_section_field(): void
    {
        $tenant = $this->makeTenant(['code' => 'TPL3-'.Str::random(4)]);
        $service = app(DocumentTemplateService::class);
        $set = $service->findOrCreateSet($tenant->id, 'work_order', 'TENANT', null, 'WO Template');
        $draft = $service->createDraft($set, ['html' => '{{#jobs}}{{nonexistent_field}}{{/jobs}}'], null);

        $this->expectException(TemplateValidationException::class);
        $service->publish($draft, 'work_order', null);
    }

    public function test_inheritance_resolves_workshop_before_tenant_before_platform(): void
    {
        [$tenant, $branch, $workshop] = $this->setUpTenant();
        $configService = app(ConfigurationService::class);
        $templates = app(DocumentTemplateService::class);
        $render = app(DocumentTemplateRenderService::class);

        // No tenant/workshop override yet -> falls back to platform default (seeded).
        $platformResolved = $render->resolveEffective('work_order', $tenant->id, $branch->id, $workshop->id);
        $this->assertNotNull($platformResolved);
        $this->assertTrue($platformResolved->configurationSet->is_system);

        $tenantSet = $templates->findOrCreateSet($tenant->id, 'work_order', 'TENANT', null, 'Tenant Default');
        $templates->publish($templates->createDraft($tenantSet, ['html' => 'TENANT LEVEL {{document_number}}'], null), 'work_order', null);

        $resolvedAfterTenant = $render->resolveEffective('work_order', $tenant->id, $branch->id, $workshop->id);
        $this->assertSame('TENANT LEVEL {{document_number}}', $resolvedAfterTenant->payload['html']);

        $workshopSet = $templates->findOrCreateSet($tenant->id, 'work_order', 'WORKSHOP', $workshop->id, 'Workshop Override');
        $templates->publish($templates->createDraft($workshopSet, ['html' => 'WORKSHOP LEVEL {{document_number}}'], null), 'work_order', null);

        $resolvedAfterWorkshop = $render->resolveEffective('work_order', $tenant->id, $branch->id, $workshop->id);
        $this->assertSame('WORKSHOP LEVEL {{document_number}}', $resolvedAfterWorkshop->payload['html']);

        // A different workshop with no override still sees the tenant default, not this workshop's override.
        $otherWorkshop = $this->makeWorkshop($tenant, $branch);
        $resolvedOtherWorkshop = $render->resolveEffective('work_order', $tenant->id, $branch->id, $otherWorkshop->id);
        $this->assertSame('TENANT LEVEL {{document_number}}', $resolvedOtherWorkshop->payload['html']);
    }

    public function test_render_produces_html_with_repeating_section_and_conditional(): void
    {
        $renderer = new \App\Domain\Configuration\Services\TemplateRenderer;
        $html = $renderer->render(
            'Doc {{document_number}}{{#jobs}} - {{description}}({{status}}){{/jobs}}{{^jobs}}No jobs{{/jobs}}',
            ['document_number' => 'WO/1', 'jobs' => [['description' => 'Brake pad', 'status' => 'DONE']]]
        );
        $this->assertSame('Doc WO/1 - Brake pad(DONE)', $html);

        $emptyHtml = $renderer->render('{{#jobs}}X{{/jobs}}{{^jobs}}No jobs{{/jobs}}', ['jobs' => []]);
        $this->assertSame('No jobs', $emptyHtml);
    }

    public function test_render_escapes_variable_values(): void
    {
        $renderer = new \App\Domain\Configuration\Services\TemplateRenderer;
        $html = $renderer->render('{{note}}', ['note' => '<script>alert(1)</script>']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_preview_does_not_require_publish_or_touch_effective_configuration(): void
    {
        $render = app(DocumentTemplateRenderService::class);
        $html = $render->preview('work_order', 'Work Order {{document_number}} for {{vehicle.registration_number}}');

        $this->assertStringContainsString('Work Order', $html);
        $this->assertStringContainsString('SAMPLE/2026/000001', $html);
    }

    public function test_issued_work_order_pdf_preserves_document_and_template_version_after_republish(): void
    {
        [$tenant, $branch, $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($token))->assertStatus(201)->json('data.id');

        $first = $this->getJson('/api/v1/app/work-orders/'.$woId.'/print', $this->authHeaders($token));
        $first->assertStatus(200);
        $this->assertSame('application/pdf', $first->headers->get('Content-Type'));

        // Republishing the platform default template must not break rendering
        // an already-issued document — it always renders through the CURRENT
        // effective template, but the document's own number/config version
        // never changes.
        $templates = app(DocumentTemplateService::class);
        $configService = app(ConfigurationService::class);
        $set = $configService->findOrCreateSet(null, 'TEMPLATE', 'work_order', 'TENANT', null, 'Work Order Template', true);
        $templates->publish($templates->createDraft($set, ['html' => 'REPUBLISHED {{document_number}}'], null), 'work_order', null);

        $second = $this->getJson('/api/v1/app/work-orders/'.$woId.'/print', $this->authHeaders($token));
        $second->assertStatus(200);
    }

    public function test_cross_tenant_work_order_print_is_denied(): void
    {
        [$tenantA, $branchA, $workshopA, $vehicleA] = $this->setUpTenant();
        [, $tokenA] = $this->makeTenantUser($tenantA, ['work_order.view', 'work_order.create']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicleA->id, 'workshop_id' => $workshopA->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($tokenA))->assertStatus(201)->json('data.id');

        [$tenantB] = $this->setUpTenant();
        [, $tokenB] = $this->makeTenantUser($tenantB, ['work_order.view']);

        $this->getJson('/api/v1/app/work-orders/'.$woId.'/print', $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_print_without_permission_is_denied(): void
    {
        [$tenant, $branch, $workshop, $vehicle] = $this->setUpTenant();
        [, $creatorToken] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($creatorToken))->assertStatus(201)->json('data.id');

        [, $noPermToken] = $this->makeTenantUser($tenant, []);

        $this->getJson('/api/v1/app/work-orders/'.$woId.'/print', $this->authHeaders($noPermToken))->assertStatus(403);
    }

    public function test_purchase_order_print_renders_pdf_from_platform_default_template(): void
    {
        $tenant = $this->makeTenant(['code' => 'TPLPO-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $this->grantModule($tenant, 'PARTNER');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);
        $vendor = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER']);
        [, $token] = $this->makeTenantUser($tenant, ['purchase_order.view', 'purchase_order.create']);

        $poId = $this->postJson('/api/v1/app/purchase-orders', [
            'partner_id' => $vendor->id,
            'delivery_warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity_ordered' => 5, 'unit_price' => 10]],
        ], $this->authHeaders($token))->assertStatus(201)->json('data.id');

        $response = $this->getJson('/api/v1/app/purchase-orders/'.$poId.'/print', $this->authHeaders($token));
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
