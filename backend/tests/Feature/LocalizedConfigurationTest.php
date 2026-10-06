<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\TemplateValidationException;
use App\Domain\Notification\Services\NotificationTemplateService;
use App\Domain\Notification\Services\NotificationTemplateValidator;
use App\Domain\Workflow\Services\WorkflowDefinitionValidator;
use App\Domain\Workflow\Services\WorkflowValidationException;
use App\Domain\Workflow\Support\WorkflowLabels;
use Tests\TestCase;

/**
 * i18n structural preparation S8: tenant-editable workflow labels and notification wording are localized
 * inside their own versioned configuration (`payload.locales.<locale>`), validated on publish, with a
 * fallback to the version's own text. Nothing changes for a version without `locales`.
 */
class LocalizedConfigurationTest extends TestCase
{
    private function publishedVersion(string $type): array
    {
        $setIds = ConfigurationSet::query()->withoutGlobalScopes()->where('type', $type)->pluck('id');

        return [ConfigurationVersion::query()->whereIn('configuration_set_id', $setIds)->where('status', 'PUBLISHED')->firstOrFail()];
    }

    public function test_notification_wording_per_locale_with_fallback_and_validation(): void
    {
        [$version] = $this->publishedVersion(ConfigurationSet::TYPE_NOTIFICATION);
        $eventCode = ConfigurationSet::query()->withoutGlobalScopes()->findOrFail($version->configuration_set_id)->code;
        $payload = $version->payload;
        $channel = array_key_first($payload['channels']);
        $payload['locales']['id']['channels'][$channel] = ['subject' => 'Pemberitahuan', 'body' => 'Isi pemberitahuan'];

        $validator = app(NotificationTemplateValidator::class);
        $validator->validate($eventCode, $payload);

        $localized = new ConfigurationVersion(['payload' => $payload]);
        $service = app(NotificationTemplateService::class);
        $this->assertSame('Isi pemberitahuan', $service->render($localized, $channel, [], 'id')['body']);
        $this->assertSame($service->render($version, $channel, [])['body'], $service->render($localized, $channel, [], 'en')['body'], 'No en entry: the version own wording.');
        $this->assertSame($service->render($version, $channel, [])['body'], $service->render($localized, $channel, [])['body'], 'No locale → unchanged.');

        foreach ([
            ['fr' => ['channels' => [$channel => ['subject' => 'x', 'body' => 'x']]]],
            ['id' => ['channels' => [$channel => ['subject' => 'x', 'body' => '{{secret.field}}']]]],
            ['id' => ['channels' => [($channel === 'EMAIL' ? 'IN_APP' : 'EMAIL') => ['subject' => 'x', 'body' => 'x']]]],
        ] as $locales) {
            try {
                $single = ['channels' => [$channel => $version->payload['channels'][$channel]]] + $version->payload;
                $validator->validate($eventCode, ['locales' => $locales] + $single);
                $this->fail('Invalid localized wording must not publish: '.json_encode($locales));
            } catch (TemplateValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_workflow_action_labels_per_locale_with_fallback_and_validation(): void
    {
        [$version] = $this->publishedVersion(ConfigurationSet::TYPE_WORKFLOW);
        $payload = $version->payload;
        $transition = $payload['transitions'][0];
        $payload['locales']['id']['action_labels'][$transition['action_code']] = 'Label ID';

        $this->assertSame('Label ID', WorkflowLabels::actionLabel($payload, $transition, 'id'));
        $this->assertSame($transition['action_label'] ?? $transition['action_code'], WorkflowLabels::actionLabel($payload, $transition, 'en'));
        $this->assertSame($transition['action_label'] ?? $transition['action_code'], WorkflowLabels::actionLabel($version->payload, $transition, 'id'));

        $validator = app(WorkflowDefinitionValidator::class);
        $validator->validate($payload);
        foreach ([
            ['fr' => ['action_labels' => [$transition['action_code'] => 'x']]],
            ['id' => ['action_labels' => ['NOT_AN_ACTION' => 'x']]],
            ['id' => ['action_labels' => [$transition['action_code'] => ' ']]],
        ] as $locales) {
            try {
                $validator->validate(['locales' => $locales] + $version->payload);
                $this->fail('Invalid localized labels must not publish: '.json_encode($locales));
            } catch (WorkflowValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
