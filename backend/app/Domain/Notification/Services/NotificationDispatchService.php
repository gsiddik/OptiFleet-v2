<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\NotificationDeliveryLog;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Workflow\Services\ConditionEvaluator;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Section 28/34: the single call site every domain service uses to raise a
 * domain event. Matches active rules (tenant-owned + platform-owned) for
 * the event, evaluates each rule's condition_set (Section 31, reusing the
 * same ConditionEvaluator as Batch D's workflow conditions), resolves
 * recipients, and writes one QUEUED DeliveryLog row per (recipient,
 * channel) — the actual send happens later in SendNotificationJob, after
 * the caller's transaction commits. dispatchEvent() itself never throws:
 * any failure here is caught and logged, so a broken or misconfigured
 * notification rule can never roll back the business transaction that
 * triggered it (Section 34).
 */
class NotificationDispatchService
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly RecipientResolver $recipients,
        private readonly NotificationTemplateService $templates,
    ) {}

    public function dispatchEvent(string $eventCode, string $tenantId, array $context, ?string $resourceType = null, ?string $resourceId = null): void
    {
        try {
            $this->doDispatch($eventCode, $tenantId, $context, $resourceType, $resourceId);
        } catch (Throwable $e) {
            Log::error("Notification dispatch failed for event '{$eventCode}': {$e->getMessage()}");
        }
    }

    private function doDispatch(string $eventCode, string $tenantId, array $context, ?string $resourceType, ?string $resourceId): void
    {
        $rules = NotificationRule::query()->withoutGlobalScopes()
            ->where('event_code', $eventCode)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $templateVersion = $this->templates->resolveEffective($eventCode, $tenantId);

        foreach ($rules as $rule) {
            if (! $this->conditions->evaluate($rule->condition_set, $context)) {
                continue;
            }

            $targets = $this->recipients->resolve($rule->recipient_rules, $tenantId, $context);

            foreach ($targets as $target) {
                foreach ($rule->channels as $channel) {
                    $log = NotificationDeliveryLog::query()->create([
                        'tenant_id' => $tenantId,
                        'notification_rule_id' => $rule->id,
                        'event_code' => $eventCode,
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                        'recipient_user_id' => $target['user_id'] ?? null,
                        'recipient_email' => $target['email'] ?? null,
                        'channel' => $channel,
                        'template_configuration_version_id' => $templateVersion?->id,
                        'status' => NotificationDeliveryLog::STATUS_QUEUED,
                        'queued_at' => now(),
                    ]);

                    SendNotificationJob::dispatch($log->id, $context)->afterCommit();
                }
            }
        }
    }
}
