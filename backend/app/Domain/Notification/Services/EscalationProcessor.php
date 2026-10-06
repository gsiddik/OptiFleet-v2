<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\NotificationDeliveryLog;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Workflow\Services\ConditionEvaluator;
use App\Jobs\SendNotificationJob;

/**
 * Section 32: "event -> notify recipient -> wait configured duration ->
 * if still unresolved -> notify escalation recipient" (e.g. Critical
 * Breakdown -> Workshop Manager immediately -> unresolved 2hrs -> Fleet
 * Manager). Run periodically via the notifications:process-escalations
 * console command rather than a long-lived timer. Each SENT/DELIVERED log
 * whose rule declares escalation is checked at most once — escalated_at is
 * always set once the wait has elapsed, whether or not the resource turned
 * out to already be resolved, so a log is never re-evaluated twice.
 */
class EscalationProcessor
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly RecipientResolver $recipients,
        private readonly ResourceStatusLookup $statusLookup,
        private readonly RecipientLocaleResolver $locales,
    ) {}

    public function run(): int
    {
        $escalated = 0;

        $logs = NotificationDeliveryLog::query()->withoutGlobalScopes()
            ->whereIn('status', [NotificationDeliveryLog::STATUS_SENT, NotificationDeliveryLog::STATUS_DELIVERED])
            ->whereNull('escalated_at')
            ->whereNotNull('notification_rule_id')
            ->get();

        foreach ($logs as $log) {
            if ($this->processOne($log)) {
                $escalated++;
            }
        }

        return $escalated;
    }

    private function processOne(NotificationDeliveryLog $log): bool
    {
        $rule = NotificationRule::query()->withoutGlobalScopes()->find($log->notification_rule_id);
        $escalation = $rule?->escalation;

        if (! $escalation || empty($escalation['after_minutes']) || ! $log->sent_at) {
            return false;
        }
        if ($log->sent_at->diffInMinutes(now()) < (int) $escalation['after_minutes']) {
            return false;
        }

        $context = [];
        if (! empty($escalation['unresolved_condition_set']) && $log->resource_type && $log->resource_id) {
            $status = $this->statusLookup->currentStatus($log->resource_type, $log->resource_id, $log->tenant_id);
            if ($status === null) {
                $log->update(['escalated_at' => now()]);

                return false;
            }
            $context = ['status' => $status];
            if (! $this->conditions->evaluate($escalation['unresolved_condition_set'], $context)) {
                $log->update(['escalated_at' => now()]); // resolved before the wait elapsed — no escalation needed

                return false;
            }
        }

        $targets = $this->recipients->resolve($escalation['recipient_rules'] ?? [], $log->tenant_id, $context);
        foreach ($targets as $target) {
            $escalatedLog = NotificationDeliveryLog::query()->create([
                'tenant_id' => $log->tenant_id,
                'notification_rule_id' => $log->notification_rule_id,
                'event_code' => $log->event_code,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'recipient_user_id' => $target['user_id'] ?? null,
                'recipient_email' => $target['email'] ?? null,
                'channel' => $log->channel,
                'template_configuration_version_id' => $log->template_configuration_version_id,
                'locale' => $this->locales->resolve($target, $log->tenant_id), // the escalation target's language
                'status' => NotificationDeliveryLog::STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            SendNotificationJob::dispatch($escalatedLog->id, $context)->afterCommit();
        }

        $log->update(['escalated_at' => now()]);

        return true;
    }
}
