<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Workflow\Services\ConditionEvaluator;
use Database\Seeders\NotificationDefaultsSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Configuration → Notifications form contract: the form builds exactly the existing
 * NotificationRule schema (recipient_rules / channels / condition_set / escalation) and the
 * existing NOTIFICATION message template payload — no new semantics. The server checks the
 * shape on every write, describes the schema for the form (metadata), and previews messages.
 */
class NotificationConfigurationFormTest extends TestCase
{
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'NTF-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['configuration.view', 'notification_rule.manage', 'document_template.manage', 'document_template.publish']);

        return ['tenant' => $tenant, 'headers' => $this->authHeaders($token)];
    }

    /** What the form sends for "Critical breakdown → Branch Manager + ops email, escalate to a role after 2 hours". */
    private function formRule(): array
    {
        return [
            'event_code' => 'breakdown.reported',
            'name' => 'Critical breakdown alert',
            'channels' => ['IN_APP', 'EMAIL'],
            'recipient_rules' => [['type' => 'BRANCH_MANAGER'], ['type' => 'CUSTOM_EMAIL', 'identifier' => 'ops@example.com']],
            'condition_set' => ['operator' => 'AND', 'rules' => [
                ['field' => 'breakdown.severity', 'op' => 'IN', 'value' => ['CRITICAL', 'IMMOBILIZED']],
                ['field' => 'breakdown.location', 'op' => 'IS_NOT_NULL'],
            ]],
            'escalation' => [
                'after_minutes' => 120,
                'recipient_rules' => [['type' => 'ROLE', 'identifier' => 'Fleet Manager']],
                'unresolved_condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'status', 'op' => '!=', 'value' => 'RESOLVED']]],
            ],
        ];
    }

    public function test_metadata_describes_the_existing_schema_for_the_form(): void
    {
        $s = $this->scenario();
        $meta = $this->getJson('/api/v1/app/configuration/metadata?type=NOTIFICATION', $s['headers'])->assertOk()->json('data');

        $breakdown = collect($meta['events'])->firstWhere('code', 'breakdown.reported');
        $this->assertSame('Breakdown Reported', $breakdown['label']);
        $this->assertFalse($breakdown['platform_locked']);
        $this->assertContains(['key' => 'breakdown.severity', 'label' => 'Breakdown Severity'], $breakdown['variables']);
        $this->assertTrue(collect($meta['events'])->firstWhere('code', 'invoice.due')['platform_locked']);

        $types = collect($meta['recipient_types'])->keyBy('value');
        $this->assertCount(12, $types);
        $this->assertSame('user', $types['EXPLICIT_USER']['identifier']);
        $this->assertSame('role', $types['ROLE']['identifier']);
        $this->assertSame('permission', $types['PERMISSION']['identifier']);
        $this->assertSame('email', $types['CUSTOM_EMAIL']['identifier']);
        $this->assertNull($types['REQUESTER']['identifier']);

        $this->assertSame(ConditionEvaluator::OPERATORS, array_column($meta['operators'], 'value'));
        $operators = collect($meta['operators'])->keyBy('value');
        $this->assertFalse($operators['IS_NULL']['needs_value']);
        $this->assertTrue($operators['IN']['multiple']);
        $this->assertSame(['IN_APP', 'EMAIL'], array_column($meta['channels'], 'value'));
        $this->assertSame('status', $meta['unresolved_fields'][0]['key']);

        $events = $this->getJson('/api/v1/app/notification-rules/events', $s['headers'])->assertOk()->json('data');
        $this->assertSame('Low Stock', collect($events)->firstWhere('code', 'inventory.low_stock')['label']);
    }

    public function test_form_rule_is_stored_as_the_existing_schema_and_edits_round_trip(): void
    {
        $s = $this->scenario();
        $form = $this->formRule();
        $rule = $this->postJson('/api/v1/app/notification-rules', $form, $s['headers'])->assertStatus(201)->json('data');
        foreach (['channels', 'recipient_rules', 'condition_set', 'escalation'] as $key) {
            $this->assertEquals($form[$key], $rule[$key], "{$key} stored as sent");
        }

        // The stored condition means what the form showed (evaluated by the existing engine).
        $evaluator = app(ConditionEvaluator::class);
        $this->assertTrue($evaluator->evaluate($rule['condition_set'], ['breakdown' => ['severity' => 'CRITICAL', 'location' => 'KM 12']]));
        $this->assertFalse($evaluator->evaluate($rule['condition_set'], ['breakdown' => ['severity' => 'LOW', 'location' => 'KM 12']]));
        $this->assertFalse($evaluator->evaluate($rule['condition_set'], ['breakdown' => ['severity' => 'CRITICAL', 'location' => null]]));

        // Edit: remove the condition and the escalation, change recipients.
        $updated = $this->putJson("/api/v1/app/notification-rules/{$rule['id']}", [
            'name' => 'All breakdowns', 'channels' => ['IN_APP'], 'recipient_rules' => [['type' => 'PERMISSION', 'identifier' => 'breakdown.view']],
            'condition_set' => null, 'escalation' => null,
        ], $s['headers'])->assertOk()->json('data');
        $this->assertSame('All breakdowns', $updated['name']);
        $this->assertNull($updated['condition_set']);
        $this->assertNull($updated['escalation']);
        $this->assertSame([['type' => 'PERMISSION', 'identifier' => 'breakdown.view']], $updated['recipient_rules']);
    }

    public function test_invalid_rule_shapes_are_rejected_server_side(): void
    {
        $s = $this->scenario();
        $cases = [
            'unknown channel' => ['channels' => ['SMS']],
            'unknown recipient type' => ['recipient_rules' => [['type' => 'EVERYONE']]],
            'role without a role' => ['recipient_rules' => [['type' => 'ROLE', 'identifier' => ' ']]],
            'invalid email' => ['recipient_rules' => [['type' => 'CUSTOM_EMAIL', 'identifier' => 'not-an-email']]],
            'unknown operator' => ['condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'breakdown.severity', 'op' => 'LIKE', 'value' => 'x']]]],
            'condition without field' => ['condition_set' => ['operator' => 'AND', 'rules' => [['field' => '', 'op' => '=', 'value' => 'x']]]],
            'unknown match' => ['condition_set' => ['operator' => 'XOR', 'rules' => []]],
            'escalation without wait' => ['escalation' => ['after_minutes' => 0, 'recipient_rules' => [['type' => 'REQUESTER']]]],
            'escalation without recipients' => ['escalation' => ['after_minutes' => 30, 'recipient_rules' => []]],
        ];
        foreach ($cases as $label => $override) {
            $this->postJson('/api/v1/app/notification-rules', array_merge($this->formRule(), $override), $s['headers'])
                ->assertStatus(422, $label);
        }
        $this->assertSame(0, NotificationRule::query()->where('tenant_id', $s['tenant']->id)->count());

        // Edits are checked the same way.
        $id = $this->postJson('/api/v1/app/notification-rules', $this->formRule(), $s['headers'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/v1/app/notification-rules/{$id}", ['recipient_rules' => [['type' => 'EXPLICIT_USER']]], $s['headers'])->assertStatus(422);

        // Platform rules stay read-only.
        $this->seed(NotificationDefaultsSeeder::class);
        $system = NotificationRule::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('is_system', true)->firstOrFail();
        $this->putJson("/api/v1/app/notification-rules/{$system->id}", ['name' => 'Hijack'], $s['headers'])->assertStatus(422);
    }

    public function test_message_template_draft_preview_publish_and_return_to_default(): void
    {
        $this->seed(NotificationDefaultsSeeder::class);
        $s = $this->scenario();
        $payload = ['channels' => [
            'IN_APP' => ['body' => 'Breakdown on {{vehicle.registration_number}} ({{breakdown.severity}})'],
            'EMAIL' => ['subject' => 'Breakdown: {{vehicle.registration_number}}', 'body' => "Location: {{breakdown.location}}\nCompany: {{tenant.name}}"],
        ]];

        $preview = $this->postJson('/api/v1/app/configuration/preview', ['type' => 'NOTIFICATION', 'code' => 'breakdown.reported', 'payload' => $payload], $s['headers'])->assertOk()->json('data.channels');
        $this->assertSame('Breakdown on [Vehicle Registration Number] ([Breakdown Severity])', $preview['IN_APP']['body']);
        $this->assertSame('Breakdown: [Vehicle Registration Number]', $preview['EMAIL']['subject']);
        $this->assertStringContainsString('Company: [Company Name]', $preview['EMAIL']['body']);
        $this->postJson('/api/v1/app/configuration/preview', ['type' => 'NOTIFICATION', 'code' => 'breakdown.reported', 'payload' => ['channels' => ['IN_APP' => ['body' => '{{vehicle.secret}}']]]], $s['headers'])->assertStatus(422);

        $id = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'NOTIFICATION', 'code' => 'breakdown.reported', 'name' => 'Our breakdown message', 'payload' => $payload], $s['headers'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$id}/publish", [], $s['headers'])->assertOk();
        $set = ConfigurationSet::query()->where('tenant_id', $s['tenant']->id)->where('type', 'NOTIFICATION')->where('code', 'breakdown.reported')->firstOrFail();
        $this->assertEquals($payload, $set->publishedVersion()->payload, 'message stored exactly as written (spacing and line breaks kept)');

        $this->postJson("/api/v1/app/configuration/sets/{$set->id}/restore-default", [], $s['headers'])->assertOk();
        $this->assertNull($set->fresh()->publishedVersion());

        // Platform-controlled and unknown events cannot get a tenant message template.
        $this->postJson('/api/v1/app/configuration/versions', ['type' => 'NOTIFICATION', 'code' => 'invoice.due', 'name' => 'X', 'payload' => $payload], $s['headers'])->assertStatus(422);
        $this->postJson('/api/v1/app/configuration/versions', ['type' => 'NOTIFICATION', 'code' => 'nope.event', 'name' => 'X', 'payload' => $payload], $s['headers'])->assertStatus(422);
    }
}
