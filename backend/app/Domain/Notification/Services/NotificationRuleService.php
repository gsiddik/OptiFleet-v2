<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Workflow\Services\ConditionEvaluator;
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
    public const CHANNELS = ['IN_APP', 'EMAIL'];

    /**
     * Recipient types RecipientResolver understands, with what their `identifier` holds:
     * a tenant user id, a role name, a permission name, an email address, or nothing (the
     * recipient comes from the event itself).
     */
    public const RECIPIENT_TYPES = [
        'EXPLICIT_USER' => 'user', 'ROLE' => 'role', 'PERMISSION' => 'permission', 'CUSTOM_EMAIL' => 'email',
        'BRANCH_MANAGER' => null, 'WORKSHOP_MANAGER' => null, 'REQUESTER' => null, 'APPROVER' => null,
        'ASSIGNED_MECHANIC' => null, 'VEHICLE_PIC' => null, 'WAREHOUSE_PIC' => null, 'VENDOR_CONTACT' => null,
    ];

    public function __construct(private readonly NotificationEventCatalog $catalog) {}

    public function create(?string $tenantId, string $eventCode, string $name, array $recipientRules, array $channels, ?array $conditionSet = null, ?array $escalation = null, bool $isSystem = false): NotificationRule
    {
        if (! $this->catalog->isKnownEvent($eventCode)) {
            throw new NotificationException("Unknown notification event '{$eventCode}'.");
        }
        if ($tenantId !== null && $this->catalog->isPlatformLocked($eventCode)) {
            throw new NotificationException("Event '{$eventCode}' is platform-controlled and cannot be configured by a tenant.");
        }
        $this->assertValidDefinition($recipientRules, $channels, $conditionSet, $escalation);

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
            $this->assertValidDefinition(
                $attributes['recipient_rules'] ?? $locked->recipient_rules,
                $attributes['channels'] ?? $locked->channels,
                array_key_exists('condition_set', $attributes) ? $attributes['condition_set'] : $locked->condition_set,
                array_key_exists('escalation', $attributes) ? $attributes['escalation'] : $locked->escalation,
            );
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

    /**
     * The rule's shape is checked on every write, so a saved rule always means what the
     * configuration screen showed: known channels and recipient types, each recipient's
     * identifier where its type needs one, ConditionEvaluator operators only, and an
     * escalation that has a wait time and someone to escalate to.
     */
    private function assertValidDefinition(array $recipientRules, array $channels, ?array $conditionSet, ?array $escalation): void
    {
        if ($channels === [] || array_diff($channels, self::CHANNELS) !== []) {
            throw new NotificationException('Choose at least one channel: In-App or Email.');
        }
        $this->assertValidRecipients($recipientRules, 'recipient');
        $this->assertValidConditions($conditionSet);
        if ($escalation === null || $escalation === []) {
            return;
        }
        $minutes = $escalation['after_minutes'] ?? null;
        if ((! is_int($minutes) && ! (is_string($minutes) && ctype_digit($minutes))) || (int) $minutes < 1) {
            throw new NotificationException('Escalation needs a waiting time of at least 1 minute.');
        }
        $this->assertValidRecipients((array) ($escalation['recipient_rules'] ?? []), 'escalation recipient');
        $this->assertValidConditions($escalation['unresolved_condition_set'] ?? null);
    }

    private function assertValidRecipients(array $rules, string $what): void
    {
        if ($rules === []) {
            throw new NotificationException("Add at least one {$what}.");
        }
        foreach ($rules as $rule) {
            $type = is_array($rule) ? ($rule['type'] ?? null) : null;
            if (! is_string($type) || ! array_key_exists($type, self::RECIPIENT_TYPES)) {
                throw new NotificationException("Unknown {$what} type.");
            }
            $kind = self::RECIPIENT_TYPES[$type];
            $identifier = $rule['identifier'] ?? null;
            if ($kind !== null && (! is_string($identifier) || trim($identifier) === '')) {
                throw new NotificationException("Choose the {$kind} for each {$what}.");
            }
            if ($kind === 'email' && ! filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                throw new NotificationException("'{$identifier}' is not a valid email address.");
            }
        }
    }

    private function assertValidConditions(?array $group, int $depth = 0): void
    {
        if ($group === null || $group === []) {
            return;
        }
        if ($depth > 5 || ! in_array(strtoupper((string) ($group['operator'] ?? 'AND')), ['AND', 'OR'], true)) {
            throw new NotificationException('Conditions must be matched with All (AND) or Any (OR).');
        }
        foreach ((array) ($group['rules'] ?? []) as $rule) {
            if (is_array($rule) && array_key_exists('rules', $rule)) {
                $this->assertValidConditions($rule, $depth + 1);

                continue;
            }
            if (! is_array($rule) || ! is_string($rule['field'] ?? null) || ! preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $rule['field'])) {
                throw new NotificationException('Each condition needs a field.');
            }
            if (! in_array($rule['op'] ?? '=', ConditionEvaluator::OPERATORS, true)) {
                throw new NotificationException('Unknown condition operator.');
            }
        }
    }
}
