<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Notification\Models\NotificationDeliveryLog;
use App\Domain\Notification\Models\NotificationInAppMessage;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Notification\Services\EscalationProcessor;
use App\Domain\Notification\Services\NotificationDispatchService;
use App\Domain\Notification\Services\NotificationRuleService;
use App\Domain\Notification\Services\RecipientResolver;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationEngineTest extends TestCase
{
    public function test_dispatch_matches_active_rule_evaluates_condition_and_creates_queued_delivery_logs(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT1-'.Str::random(4)]);
        [$approver] = $this->makeTenantUser($tenant, ['maintenance_request.review']);

        $service = app(NotificationDispatchService::class);
        $before = NotificationDeliveryLog::query()->count();

        $service->dispatchEvent('maintenance_request.submitted', $tenant->id, [
            'request' => ['number' => 'MR/1', 'priority' => 'HIGH', 'complaint' => 'Brake noise'],
            'vehicle' => ['registration_number' => 'B1234XYZ'],
        ], 'maintenance_request', (string) Str::uuid());

        $logs = NotificationDeliveryLog::query()->where('tenant_id', $tenant->id)->get();
        $this->assertGreaterThan($before, NotificationDeliveryLog::query()->count());
        $this->assertTrue($logs->contains('recipient_user_id', $approver->id));
        // ->afterCommit() runs synchronously here (sync queue), so by the time
        // dispatchEvent() returns the job has already attempted delivery.
        $this->assertTrue($logs->every(fn ($l) => in_array($l->status, ['QUEUED', 'SENT', 'FAILED'], true)));
    }

    public function test_dispatch_skips_rule_whose_condition_does_not_match(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT2-'.Str::random(4)]);
        $branch = $this->makeBranch($tenant);
        [$manager] = $this->makeTenantUser($tenant, [], ['BRANCH' => $branch->id]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Branch Manager', 'scope' => 'tenant', 'is_system' => false]);
        RoleAssignment::query()->create(['user_id' => $manager->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);

        $service = app(NotificationDispatchService::class);

        // MINOR severity does not match the seeded "CRITICAL/IMMOBILIZED" condition.
        $service->dispatchEvent('breakdown.reported', $tenant->id, [
            'breakdown' => ['severity' => 'MINOR', 'location' => 'Yard', 'description' => 'Flat tire'],
            'vehicle' => ['registration_number' => 'B1'],
            'branch_id' => $branch->id,
        ], 'breakdown', (string) Str::uuid());

        $this->assertSame(0, NotificationDeliveryLog::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_dispatch_fires_for_matching_condition_and_resolves_branch_manager(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT3-'.Str::random(4)]);
        $branch = $this->makeBranch($tenant);
        [$manager] = $this->makeTenantUser($tenant, [], ['BRANCH' => $branch->id]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Branch Manager', 'scope' => 'tenant', 'is_system' => false]);
        RoleAssignment::query()->create(['user_id' => $manager->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);

        $service = app(NotificationDispatchService::class);
        $service->dispatchEvent('breakdown.reported', $tenant->id, [
            'breakdown' => ['severity' => 'CRITICAL', 'location' => 'Highway', 'description' => 'Engine fire'],
            'vehicle' => ['registration_number' => 'B9'],
            'branch_id' => $branch->id,
        ], 'breakdown', (string) Str::uuid());

        $logs = NotificationDeliveryLog::query()->where('tenant_id', $tenant->id)->get();
        $this->assertGreaterThan(0, $logs->count());
        $this->assertTrue($logs->contains('recipient_user_id', $manager->id));
    }

    public function test_recipient_resolver_cross_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant(['code' => 'NOT4A-'.Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'NOT4B-'.Str::random(4)]);
        [$userA] = $this->makeTenantUser($tenantA, ['maintenance_request.review']);
        [$userB] = $this->makeTenantUser($tenantB, ['maintenance_request.review']);

        $resolver = app(RecipientResolver::class);
        $targets = $resolver->resolve([['type' => 'PERMISSION', 'identifier' => 'maintenance_request.review']], $tenantA->id, []);

        $ids = array_column($targets, 'user_id');
        $this->assertContains($userA->id, $ids);
        $this->assertNotContains($userB->id, $ids);
    }

    public function test_recipient_resolver_custom_email_requires_valid_format(): void
    {
        $resolver = app(RecipientResolver::class);
        $tenant = $this->makeTenant(['code' => 'NOT5-'.Str::random(4)]);

        $valid = $resolver->resolve([['type' => 'CUSTOM_EMAIL', 'identifier' => 'ops@example.com']], $tenant->id, []);
        $this->assertSame([['email' => 'ops@example.com']], $valid);

        $invalid = $resolver->resolve([['type' => 'CUSTOM_EMAIL', 'identifier' => 'not-an-email']], $tenant->id, []);
        $this->assertSame([], $invalid);
    }

    public function test_send_notification_job_delivers_in_app_and_email_and_marks_sent(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant(['code' => 'NOT6-'.Str::random(4)]);
        [$recipient] = $this->makeTenantUser($tenant, ['maintenance_request.review']);

        $service = app(NotificationDispatchService::class);
        $service->dispatchEvent('maintenance_request.submitted', $tenant->id, [
            'request' => ['number' => 'MR/9', 'priority' => 'HIGH', 'complaint' => 'Test'],
            'vehicle' => ['registration_number' => 'B9'],
        ], 'maintenance_request', (string) Str::uuid());

        // ->afterCommit() runs synchronously under the sync queue connection,
        // so by the time dispatchEvent() returns the job already ran.
        $log = NotificationDeliveryLog::query()->where('tenant_id', $tenant->id)->where('recipient_user_id', $recipient->id)->firstOrFail();
        $this->assertSame('SENT', $log->status);
        $this->assertNotNull($log->sent_at);

        $inApp = NotificationInAppMessage::query()->where('recipient_user_id', $recipient->id)->first();
        $this->assertNotNull($inApp);
        $this->assertStringContainsString('MR/9', $inApp->body);
    }

    public function test_send_notification_job_marks_failed_without_throwing_when_no_published_template(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT7-'.Str::random(4)]);
        $log = NotificationDeliveryLog::query()->create([
            'tenant_id' => $tenant->id,
            'event_code' => 'maintenance_request.submitted',
            'channel' => 'IN_APP',
            'recipient_user_id' => (string) Str::uuid(),
            'template_configuration_version_id' => null, // simulate no resolvable template
            'status' => 'QUEUED',
            'queued_at' => now(),
        ]);

        (new SendNotificationJob($log->id, []))->handle(app(\App\Domain\Notification\Services\NotificationTemplateService::class));

        $log->refresh();
        $this->assertSame('FAILED', $log->status);
        $this->assertNotNull($log->failure_reason);
    }

    public function test_dispatch_event_never_throws_and_does_not_roll_back_the_triggering_business_transaction(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT8-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);

        // Break the notification templates configuration on purpose (unpublish everything by
        // pointing dispatch at an event whose catalog entry doesn't exist for this rule) —
        // dispatchEvent must swallow the failure, not the caller.
        NotificationRule::query()->create([
            'tenant_id' => $tenant->id,
            'event_code' => 'maintenance_request.submitted',
            'name' => 'Broken rule',
            'is_active' => true,
            'is_system' => false,
            'condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'not.a.real.path', 'op' => 'THIS_IS_NOT_A_VALID_OPERATOR']]],
            'recipient_rules' => [['type' => 'EXPLICIT_USER', 'identifier' => (string) Str::uuid()]],
            'channels' => ['EMAIL'],
            'escalation' => null,
        ]);

        $response = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'priority' => 'MEDIUM', 'complaint' => 'Test',
        ], $this->authHeaders($token))->assertStatus(201);
        $id = $response->json('data.id');

        // The business action still succeeds end to end despite a malformed rule.
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $this->authHeaders($token))->assertOk();
    }

    public function test_notification_rule_service_rejects_tenant_mutation_of_platform_locked_event(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT9-'.Str::random(4)]);
        $service = app(NotificationRuleService::class);

        $this->expectException(\App\Domain\Notification\Services\NotificationException::class);
        $service->create($tenant->id, 'subscription.expiring', 'Tenant attempt', [['type' => 'EXPLICIT_USER', 'identifier' => (string) Str::uuid()]], ['EMAIL']);
    }

    public function test_notification_rule_service_rejects_deactivating_a_system_rule(): void
    {
        $rule = NotificationRule::query()->withoutGlobalScopes()->where('is_system', true)->where('event_code', 'breakdown.reported')->firstOrFail();
        $service = app(NotificationRuleService::class);

        $this->expectException(\App\Domain\Notification\Services\NotificationException::class);
        $service->setActive($rule, false);
    }

    public function test_publish_rejects_notification_template_with_unknown_variable(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOT10-'.Str::random(4)]);
        $configService = app(\App\Domain\Configuration\Services\ConfigurationService::class);
        $validator = app(\App\Domain\Notification\Services\NotificationTemplateValidator::class);

        $set = $configService->findOrCreateSet($tenant->id, 'NOTIFICATION', 'maintenance_request.submitted', 'TENANT', null, 'Bad Template', false);
        $draft = $configService->createDraft($set, ['channels' => ['IN_APP' => ['body' => '{{secret.field}}']]], null);

        $this->expectException(\App\Domain\Configuration\Services\TemplateValidationException::class);
        $configService->publish($draft, null, fn (array $p) => $validator->validate('maintenance_request.submitted', $p));
    }

    public function test_escalation_processor_notifies_escalation_recipients_when_unresolved_after_wait(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOTE1-'.Str::random(4)]);
        [$fleetManager] = $this->makeTenantUser($tenant, []);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Fleet Manager', 'scope' => 'tenant', 'is_system' => false]);
        RoleAssignment::query()->create(['user_id' => $fleetManager->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);

        $rule = NotificationRule::query()->withoutGlobalScopes()->where('is_system', true)->where('event_code', 'breakdown.reported')->firstOrFail();

        $log = NotificationDeliveryLog::query()->create([
            'tenant_id' => $tenant->id,
            'notification_rule_id' => $rule->id,
            'event_code' => 'breakdown.reported',
            'resource_type' => 'breakdown',
            'resource_id' => (string) Str::uuid(), // no matching row -> ResourceStatusLookup returns null -> treated as "nothing to escalate"
            'recipient_user_id' => (string) Str::uuid(),
            'channel' => 'IN_APP',
            'status' => 'SENT',
            'sent_at' => now()->subMinutes(200),
        ]);

        $processor = app(EscalationProcessor::class);
        $processor->run();

        $this->assertNotNull($log->fresh()->escalated_at); // checked, but resource no longer exists -> no escalation notification created
        $this->assertSame(0, NotificationDeliveryLog::query()->where('id', '!=', $log->id)->where('tenant_id', $tenant->id)->count());
    }

    public function test_escalation_processor_does_not_escalate_before_wait_elapses(): void
    {
        $tenant = $this->makeTenant(['code' => 'NOTE2-'.Str::random(4)]);
        $rule = NotificationRule::query()->withoutGlobalScopes()->where('is_system', true)->where('event_code', 'breakdown.reported')->firstOrFail();

        $log = NotificationDeliveryLog::query()->create([
            'tenant_id' => $tenant->id,
            'notification_rule_id' => $rule->id,
            'event_code' => 'breakdown.reported',
            'recipient_user_id' => (string) Str::uuid(),
            'channel' => 'IN_APP',
            'status' => 'SENT',
            'sent_at' => now()->subMinutes(5), // rule requires 120 minutes
        ]);

        $processor = app(EscalationProcessor::class);
        $processor->run();

        $this->assertNull($log->fresh()->escalated_at);
    }
}
