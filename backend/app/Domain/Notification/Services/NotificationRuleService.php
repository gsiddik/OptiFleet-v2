<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\NotificationRule;
use Illuminate\Support\Facades\DB;

/**
 * Section 36: a tenant may create/edit/deactivate a rule for any event
 * NotificationEventCatalog does not mark platform_locked. Platform-locked
 * events (subscription/invoice/payment lifecycle) are only ever
 * authored/edited here with $tenantId === null (a platform-scope caller);
 * any tenant-scoped mutation targeting one — or targeting an existing
 * is_system row at all — is rejected outright, satisfying "tenant config
 * must not be able to disable unless explicitly allowed."
 */
class NotificationRuleService
{
    public function __construct(private readonly NotificationEventCatalog $catalog) {}

    public function create(?string $tenantId, string $eventCode, string $name, array $recipientRules, array $channels, ?array $conditionSet = null, ?array $escalation = null, bool $isSystem = false): NotificationRule
    {
        if (! $this->catalog->isKnownEvent($eventCode)) {
            throw new NotificationException("Unknown notification event '{$eventCode}'.");
        }
        if ($tenantId !== null && $this->catalog->isPlatformLocked($eventCode)) {
            throw new NotificationException("Event '{$eventCode}' is platform-controlled and cannot be configured by a tenant.");
        }

        return NotificationRule::query()->create([
            'tenant_id' => $tenantId,
            'event_code' => $eventCode,
            'name' => $name,
            'is_active' => true,
            'is_system' => $isSystem,
            'condition_set' => $conditionSet,
            'recipient_rules' => $recipientRules,
            'channels' => $channels,
            'escalation' => $escalation,
        ]);
    }

    public function update(NotificationRule $rule, array $attributes): NotificationRule
    {
        return DB::transaction(function () use ($rule, $attributes) {
            $locked = NotificationRule::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($rule->id);
            $this->assertMutable($locked);
            $locked->update($attributes);

            return $locked->fresh();
        });
    }

    public function setActive(NotificationRule $rule, bool $active): NotificationRule
    {
        return DB::transaction(function () use ($rule, $active) {
            $locked = NotificationRule::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($rule->id);
            $this->assertMutable($locked);
            $locked->update(['is_active' => $active]);

            return $locked->fresh();
        });
    }

    private function assertMutable(NotificationRule $rule): void
    {
        if ($rule->is_system || $this->catalog->isPlatformLocked($rule->event_code)) {
            throw new NotificationException('This is a platform-controlled notification rule and cannot be modified by a tenant.');
        }
    }
}
