<?php

namespace App\Domain\Notification\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\EffectiveConfigurationResolver;
use App\Domain\Configuration\Services\TemplateRenderer;

/**
 * Section 33: notification message templates (subject/body per channel)
 * reuse the exact DRAFT/PUBLISHED/ARCHIVED + inheritance machinery from
 * Batch A/C (type=NOTIFICATION, code=event_code) rather than a separate
 * versioning scheme — the only notification-specific rule is the publish
 * gate (NotificationTemplateValidator) and which variables are legal per
 * event (NotificationEventCatalog).
 */
class NotificationTemplateService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly NotificationTemplateValidator $validator,
        private readonly EffectiveConfigurationResolver $resolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function findOrCreateSet(?string $tenantId, string $eventCode, string $scopeType, ?string $scopeResourceId, string $name, bool $isSystem = false): ConfigurationSet
    {
        return $this->configuration->findOrCreateSet($tenantId, ConfigurationSet::TYPE_NOTIFICATION, $eventCode, $scopeType, $scopeResourceId, $name, $isSystem);
    }

    public function createDraft(ConfigurationSet $set, array $payload, ?string $userId, ?string $changeSummary = null): ConfigurationVersion
    {
        return $this->configuration->createDraft($set, $payload, $userId, $changeSummary);
    }

    public function publish(ConfigurationVersion $version, string $eventCode, ?string $userId): ConfigurationVersion
    {
        return $this->configuration->publish($version, $userId, fn (array $payload) => $this->validator->validate($eventCode, $payload));
    }

    public function resolveEffective(string $eventCode, string $tenantId, ?string $branchId = null, ?string $workshopId = null): ?ConfigurationVersion
    {
        return $this->resolver->resolve(ConfigurationSet::TYPE_NOTIFICATION, $eventCode, $tenantId, $branchId, $workshopId);
    }

    public function render(ConfigurationVersion $version, string $channel, array $context): array
    {
        $content = $version->payload['channels'][$channel] ?? null;
        if (! $content) {
            throw new NotificationException("Template for this rule has no '{$channel}' content.");
        }

        $body = $this->renderer->render($content['body'], $context);
        $subject = isset($content['subject']) ? $this->sanitizeSubject($this->renderer->render($content['subject'], $context)) : null;

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Section 51: defense-in-depth against header injection — a rendered
     * subject can never contain a line break, so a variable value could
     * never be used to smuggle extra mail headers even if the underlying
     * mail transport were less careful than Symfony Mailer already is.
     */
    private function sanitizeSubject(string $subject): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $subject));
    }
}
