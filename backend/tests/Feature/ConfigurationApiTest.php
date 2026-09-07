<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Section 39-44: the tenant portal Configuration API — list/draft/publish/
 * archive/history/preview/metadata across NUMBERING/TEMPLATE/WORKFLOW, and
 * the separate (versionless) NotificationRule CRUD.
 */
class ConfigurationApiTest extends TestCase
{
    private function tenantWithConfigPermissions(array $extra = []): array
    {
        $tenant = $this->makeTenant(['code' => 'CFG-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, [
            'configuration.view', 'configuration_history.view',
            'numbering.manage', 'numbering.publish',
            'document_template.manage', 'document_template.publish',
            'workflow.manage', 'workflow.publish', 'workflow.simulate',
            'notification_rule.manage',
            ...$extra,
        ]);

        return [$tenant, $token];
    }

    public function test_numbering_draft_create_preview_and_publish_via_api(): void
    {
        [$tenant, $token] = $this->tenantWithConfigPermissions();
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'NUMBERING', 'code' => 'purchase_request', 'name' => 'PR Numbering',
            'payload' => ['format' => '{DOC}/{YYYY}/{SEQ:4}', 'doc_code' => 'PR', 'reset_rule' => 'YEARLY'],
        ], $headers)->assertStatus(201);
        $versionId = $draft->json('data.id');
        $this->assertSame('DRAFT', $draft->json('data.status'));

        $preview = $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'NUMBERING', 'code' => 'purchase_request',
            'payload' => ['format' => '{DOC}/{YYYY}/{SEQ:4}', 'doc_code' => 'PR'],
        ], $headers)->assertOk();
        $this->assertStringContainsString('PR/', $preview->json('data.preview'));

        $publish = $this->postJson("/api/v1/app/configuration/versions/{$versionId}/publish", [], $headers);
        $publish->assertOk()->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_numbering_publish_rejects_invalid_format_via_api(): void
    {
        [, $token] = $this->tenantWithConfigPermissions();
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'NUMBERING', 'code' => 'purchase_order', 'name' => 'Bad',
            'payload' => ['format' => '{NOT_A_REAL_TOKEN}', 'doc_code' => 'PO'],
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)
            ->assertStatus(422);
    }

    public function test_document_template_editor_flow_via_api(): void
    {
        [, $token] = $this->tenantWithConfigPermissions();
        $headers = $this->authHeaders($token);

        $variables = $this->getJson('/api/v1/app/configuration/metadata?type=TEMPLATE&code=work_order', $headers)->assertOk();
        $this->assertContains('work_order.number', $variables->json('data.variables.scalars'));

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TEMPLATE', 'code' => 'work_order', 'name' => 'Custom WO Template',
            'payload' => ['html' => 'Custom: {{document_number}}'],
        ], $headers)->assertStatus(201);

        $preview = $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'TEMPLATE', 'code' => 'work_order', 'html' => 'Custom: {{document_number}}',
        ], $headers)->assertOk();
        $this->assertStringContainsString('Custom: SAMPLE', $preview->json('data.html'));

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');
    }

    public function test_document_template_publish_rejects_unknown_variable_via_api(): void
    {
        [, $token] = $this->tenantWithConfigPermissions();
        $headers = $this->authHeaders($token);

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'TEMPLATE', 'code' => 'work_order', 'name' => 'Bad Template',
            'payload' => ['html' => '{{not.a.real.variable}}'],
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $headers)
            ->assertStatus(422);
    }

    public function test_workflow_editor_publish_and_simulate_via_api(): void
    {
        [$tenant, $token] = $this->tenantWithConfigPermissions();
        $headers = $this->authHeaders($token);

        $metadata = $this->getJson('/api/v1/app/configuration/metadata?type=WORKFLOW', $headers)->assertOk();
        $this->assertContains('SEND_NOTIFICATION', $metadata->json('data.actions'));
        $this->assertContains('IS_NOT_NULL', $metadata->json('data.operators'));

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'WORKFLOW', 'code' => 'test_ui_resource', 'name' => 'Test Workflow',
            'payload' => [
                'statuses' => [
                    ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                    ['code' => 'DONE', 'display_name' => 'Done'],
                ],
                'transitions' => [
                    ['from_status' => 'DRAFT', 'to_status' => 'DONE', 'action_code' => 'finish', 'action_label' => 'Finish'],
                ],
            ],
        ], $headers)->assertStatus(201);
        $versionId = $draft->json('data.id');

        $this->postJson("/api/v1/app/configuration/versions/{$versionId}/publish", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        $simulate = $this->postJson('/api/v1/app/configuration/preview', [
            'type' => 'WORKFLOW', 'code' => 'test_ui_resource', 'from_status' => 'DRAFT', 'context' => [],
        ], $headers)->assertOk();
        $this->assertSame('finish', $simulate->json('data.0.action_code'));
    }

    public function test_configuration_history_lists_across_types_for_tenant_only(): void
    {
        [$tenantA, $tokenA] = $this->tenantWithConfigPermissions();
        [$tenantB, $tokenB] = $this->tenantWithConfigPermissions();

        $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'NUMBERING', 'code' => 'rfq', 'name' => 'RFQ Numbering',
            'payload' => ['format' => '{DOC}/{SEQ:4}', 'doc_code' => 'RFQ'],
        ], $this->authHeaders($tokenA))->assertStatus(201);

        $historyA = $this->getJson('/api/v1/app/configuration/history?type=NUMBERING', $this->authHeaders($tokenA))->assertOk();
        $codes = collect($historyA->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('rfq'));

        $historyB = $this->getJson('/api/v1/app/configuration/history?type=NUMBERING', $this->authHeaders($tokenB))->assertOk();
        $this->assertFalse(collect($historyB->json('data'))->contains(fn ($row) => $row['code'] === 'rfq' && $row['name'] === 'RFQ Numbering'));
    }

    public function test_cross_tenant_configuration_access_is_denied(): void
    {
        [$tenantA, $tokenA] = $this->tenantWithConfigPermissions();
        [, $tokenB] = $this->tenantWithConfigPermissions();

        $draft = $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'NUMBERING', 'code' => 'goods_receipt', 'name' => 'GR Numbering',
            'payload' => ['format' => '{DOC}/{SEQ:4}', 'doc_code' => 'GR'],
        ], $this->authHeaders($tokenA))->assertStatus(201);

        $this->postJson("/api/v1/app/configuration/versions/{$draft->json('data.id')}/publish", [], $this->authHeaders($tokenB))
            ->assertStatus(404);
    }

    public function test_manage_without_permission_is_denied(): void
    {
        $tenant = $this->makeTenant(['code' => 'CFG2-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['configuration.view']);

        $this->postJson('/api/v1/app/configuration/versions', [
            'type' => 'NUMBERING', 'code' => 'warranty_claim', 'name' => 'WC Numbering',
            'payload' => ['format' => '{DOC}/{SEQ:4}', 'doc_code' => 'WC'],
        ], $this->authHeaders($token))->assertStatus(403);
    }

    public function test_notification_rule_crud_via_api(): void
    {
        [$tenant, $token] = $this->tenantWithConfigPermissions();
        $headers = $this->authHeaders($token);

        $events = $this->getJson('/api/v1/app/notification-rules/events', $headers)->assertOk();
        $this->assertTrue(collect($events->json('data'))->contains(fn ($e) => $e['code'] === 'breakdown.reported'));

        $created = $this->postJson('/api/v1/app/notification-rules', [
            'event_code' => 'inventory.low_stock',
            'name' => 'Custom low stock rule',
            'recipient_rules' => [['type' => 'CUSTOM_EMAIL', 'identifier' => 'ops@example.com']],
            'channels' => ['EMAIL'],
        ], $headers)->assertStatus(201);
        $ruleId = $created->json('data.id');

        $this->postJson("/api/v1/app/notification-rules/{$ruleId}/deactivate", [], $headers)
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->putJson("/api/v1/app/notification-rules/{$ruleId}", ['name' => 'Renamed rule'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Renamed rule');
    }

    public function test_notification_rule_platform_locked_event_rejected_via_api(): void
    {
        [, $token] = $this->tenantWithConfigPermissions();

        $this->postJson('/api/v1/app/notification-rules', [
            'event_code' => 'subscription.expiring',
            'name' => 'Tenant attempt',
            'recipient_rules' => [['type' => 'CUSTOM_EMAIL', 'identifier' => 'ops@example.com']],
            'channels' => ['EMAIL'],
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_notification_rule_cannot_deactivate_system_rule_via_api(): void
    {
        [, $token] = $this->tenantWithConfigPermissions();

        $rule = \App\Domain\Notification\Models\NotificationRule::query()->withoutGlobalScopes()
            ->where('is_system', true)->where('event_code', 'breakdown.reported')->firstOrFail();

        $this->postJson("/api/v1/app/notification-rules/{$rule->id}/deactivate", [], $this->authHeaders($token))
            ->assertStatus(422);
    }
}
