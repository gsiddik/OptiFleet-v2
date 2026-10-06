<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Notification\Models\NotificationDeliveryLog;
use App\Domain\Notification\Models\NotificationInAppMessage;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Notification\Services\EscalationProcessor;
use App\Domain\Notification\Services\NotificationDispatchService;
use App\Domain\Notification\Services\NotificationTemplateService;
use App\Domain\Notification\Services\RecipientLocaleResolver;
use App\Jobs\SendNotificationJob;
use Database\Seeders\AddLocalizedNotificationTemplatesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * i18n (notifications): each recipient gets the notification in their own language (user preference →
 * tenant default → en); the event code, variables and business data are the same for everyone.
 */
class NotificationLocalizationTest extends TestCase
{
    private function platformSet(string $eventCode): ConfigurationSet
    {
        return ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')
            ->where('type', ConfigurationSet::TYPE_NOTIFICATION)->where('code', $eventCode)->firstOrFail();
    }

    public function test_every_platform_default_template_gets_indonesian_wording_once_as_a_new_version(): void
    {
        $before = $this->platformSet('maintenance_request.submitted')->publishedVersion();
        $this->assertArrayNotHasKey('locales', $before->payload);

        $this->seed(AddLocalizedNotificationTemplatesSeeder::class);
        $this->seed(AddLocalizedNotificationTemplatesSeeder::class);

        $sets = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', ConfigurationSet::TYPE_NOTIFICATION)->get();
        $this->assertGreaterThan(0, $sets->count());
        foreach ($sets as $set) {
            $published = $set->publishedVersion();
            $this->assertSame(array_keys($published->payload['channels']), array_keys($published->payload['locales']['id']['channels']), $set->code);
            // One new version per set, no matter how often the seeder runs; the English content is unchanged.
            $this->assertSame(2, $set->versions()->count(), $set->code);
        }

        $after = $this->platformSet('maintenance_request.submitted')->publishedVersion();
        $this->assertNotSame($before->id, $after->id);
        $this->assertSame($before->payload['channels'], $after->payload['channels']);
        $this->assertSame(
            'Permintaan pemeliharaan {{request.number}} diajukan untuk {{vehicle.registration_number}}: {{request.complaint}}',
            $after->payload['locales']['id']['channels']['IN_APP']['body'],
        );
        $breakdown = $this->platformSet('breakdown.reported')->publishedVersion();
        $this->assertSame('Kerusakan dilaporkan: {{vehicle.registration_number}}', $breakdown->payload['locales']['id']['channels']['EMAIL']['subject']);
    }

    public function test_edited_wording_without_a_translation_is_not_localized(): void
    {
        $map = AddLocalizedNotificationTemplatesSeeder::translations('id');
        $this->assertNull(AddLocalizedNotificationTemplatesSeeder::localizeChannels(['IN_APP' => ['body' => 'Our own words {{request.number}}']], $map));
        $this->assertSame(
            ['EMAIL' => ['subject' => 'Pemeliharaan jatuh tempo: {{vehicle.registration_number}}', 'body' => 'Kendaraan {{vehicle.registration_number}} jatuh tempo pemeliharaan ({{schedule.policy_name}}) pada {{schedule.due_at}}.']],
            AddLocalizedNotificationTemplatesSeeder::localizeChannels(['EMAIL' => [
                'subject' => 'Maintenance due: {{vehicle.registration_number}}',
                'body' => 'Vehicle {{vehicle.registration_number}} is due for maintenance ({{schedule.policy_name}}) on {{schedule.due_at}}.',
            ]], $map),
        );
    }

    public function test_two_recipients_get_the_same_event_in_their_own_language(): void
    {
        $this->seed(AddLocalizedNotificationTemplatesSeeder::class);
        $tenant = $this->makeTenant(['code' => 'NL1-'.Str::random(4), 'default_locale' => 'en']);
        [$indonesian] = $this->makeTenantUser($tenant, ['maintenance_request.review']);
        [$english] = $this->makeTenantUser($tenant, ['maintenance_request.review']);
        $indonesian->forceFill(['preferred_locale' => 'id'])->save();

        app(NotificationDispatchService::class)->dispatchEvent('maintenance_request.submitted', $tenant->id, [
            'request' => ['number' => 'MR/77', 'priority' => 'HIGH', 'complaint' => 'Brake noise'],
            'vehicle' => ['registration_number' => 'B1234XYZ'],
        ], 'maintenance_request', (string) Str::uuid());

        $logs = NotificationDeliveryLog::query()->where('tenant_id', $tenant->id)->get()->keyBy('recipient_user_id');
        $this->assertSame('id', $logs[$indonesian->id]->locale);
        $this->assertSame('en', $logs[$english->id]->locale);
        $this->assertSame('SENT', $logs[$indonesian->id]->status);

        $idMessage = NotificationInAppMessage::query()->where('recipient_user_id', $indonesian->id)->firstOrFail();
        $enMessage = NotificationInAppMessage::query()->where('recipient_user_id', $english->id)->firstOrFail();
        $this->assertSame('Permintaan pemeliharaan MR/77 diajukan untuk B1234XYZ: Brake noise', $idMessage->body);
        $this->assertSame('Maintenance request MR/77 submitted for B1234XYZ: Brake noise', $enMessage->body);
        // Codes stay language-neutral; tenant-entered data ("Brake noise") is never translated.
        $this->assertSame('maintenance_request.submitted', $idMessage->event_code);
        $this->assertSame($enMessage->event_code, $idMessage->event_code);
    }

    public function test_recipient_language_falls_back_to_the_tenant_default_then_english(): void
    {
        $resolver = new RecipientLocaleResolver;
        $idTenant = $this->makeTenant(['code' => 'NL2-'.Str::random(4), 'default_locale' => 'id']);
        [$noPreference] = $this->makeTenantUser($idTenant, []);
        [$prefersEnglish] = $this->makeTenantUser($idTenant, []);
        $prefersEnglish->forceFill(['preferred_locale' => 'en'])->save();

        $this->assertSame('id', $resolver->resolve(['user_id' => $noPreference->id], $idTenant->id));
        $this->assertSame('en', $resolver->resolve(['user_id' => $prefersEnglish->id], $idTenant->id));
        $this->assertSame('id', $resolver->resolve(['email' => 'vendor@example.com'], $idTenant->id));

        $plainTenant = $this->makeTenant(['code' => 'NL3-'.Str::random(4)]);
        $plainTenant->forceFill(['default_locale' => null])->save();
        $this->assertSame('en', (new RecipientLocaleResolver)->resolve(['email' => 'vendor@example.com'], $plainTenant->id));
    }

    public function test_email_subject_and_body_render_in_the_recipient_language_and_old_rows_resolve_it_at_send(): void
    {
        $this->seed(AddLocalizedNotificationTemplatesSeeder::class);
        $tenant = $this->makeTenant(['code' => 'NL4-'.Str::random(4), 'default_locale' => 'id']);
        $version = $this->platformSet('breakdown.reported')->publishedVersion();
        // A row queued before the locale column existed (locale null): the language is resolved at send time.
        $log = NotificationDeliveryLog::query()->create([
            'tenant_id' => $tenant->id, 'event_code' => 'breakdown.reported', 'channel' => 'EMAIL',
            'recipient_email' => 'ops@example.com', 'template_configuration_version_id' => $version->id,
            'status' => 'QUEUED', 'queued_at' => now(),
        ]);

        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        $transport->flush();
        (new SendNotificationJob($log->id, [
            'breakdown' => ['severity' => 'CRITICAL', 'location' => 'Tol Cikampek', 'description' => 'Engine fire'],
            'vehicle' => ['registration_number' => 'B9'],
        ]))->handle(app(NotificationTemplateService::class), new RecipientLocaleResolver);

        $this->assertSame('SENT', $log->refresh()->status);
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame('Kerusakan dilaporkan: B9', $email->getSubject());
        // Severity is a canonical code and stays as stored; the sentence around it is Indonesian.
        $this->assertSame('Kerusakan CRITICAL dilaporkan untuk B9 di Tol Cikampek: Engine fire', trim($email->getTextBody()));
    }

    public function test_an_escalation_is_rendered_in_the_escalation_targets_language(): void
    {
        $this->seed(AddLocalizedNotificationTemplatesSeeder::class);
        $tenant = $this->makeTenant(['code' => 'NL5-'.Str::random(4), 'default_locale' => 'en']);
        [$fleetManager] = $this->makeTenantUser($tenant, ['maintenance_request.review']);
        $fleetManager->forceFill(['preferred_locale' => 'id'])->save();
        $rule = NotificationRule::query()->create([
            'tenant_id' => $tenant->id, 'event_code' => 'maintenance_request.submitted', 'name' => 'Escalate', 'is_active' => true,
            'recipient_rules' => [['type' => 'CUSTOM_EMAIL', 'identifier' => 'first@example.com']], 'channels' => ['IN_APP'],
            'escalation' => ['after_minutes' => 1, 'recipient_rules' => [['type' => 'EXPLICIT_USER', 'identifier' => $fleetManager->id]]],
        ]);
        $version = $this->platformSet('maintenance_request.submitted')->publishedVersion();
        NotificationDeliveryLog::query()->create([
            'tenant_id' => $tenant->id, 'notification_rule_id' => $rule->id, 'event_code' => 'maintenance_request.submitted',
            'channel' => 'IN_APP', 'recipient_email' => 'first@example.com', 'template_configuration_version_id' => $version->id,
            'locale' => 'en', 'status' => 'SENT', 'queued_at' => now()->subHour(), 'sent_at' => now()->subHour(),
        ]);

        app(EscalationProcessor::class)->run();

        $escalated = NotificationDeliveryLog::query()->where('tenant_id', $tenant->id)->where('recipient_user_id', $fleetManager->id)->firstOrFail();
        $this->assertSame('id', $escalated->locale);
    }
}
