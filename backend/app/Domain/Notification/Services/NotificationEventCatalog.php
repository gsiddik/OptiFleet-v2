<?php

namespace App\Domain\Notification\Services;

/**
 * Section 29: the platform-owned catalog of domain events a tenant may
 * attach a NotificationRule to — a tenant activates/configures rules for
 * these, it can never invent a new executable server event. Each event
 * also declares the context variable paths available to its message
 * templates (Section 33 reuses the safe template variable infrastructure).
 * Events flagged platform_locked are commercial SaaS lifecycle
 * notifications (Section 36) — a tenant can view but never create, edit,
 * or deactivate a rule attached to one.
 */
class NotificationEventCatalog
{
    private const EVENTS = [
        'maintenance.due' => ['platform_locked' => false, 'scalars' => ['vehicle.registration_number', 'schedule.due_at', 'schedule.policy_name'], 'sections' => []],
        'maintenance_request.submitted' => ['platform_locked' => false, 'scalars' => ['request.number', 'request.priority', 'request.complaint', 'vehicle.registration_number'], 'sections' => []],
        'work_order.created' => ['platform_locked' => false, 'scalars' => ['work_order.number', 'vehicle.registration_number', 'workshop.name'], 'sections' => []],
        'breakdown.reported' => ['platform_locked' => false, 'scalars' => ['breakdown.severity', 'breakdown.location', 'breakdown.description', 'vehicle.registration_number'], 'sections' => []],
        'inventory.low_stock' => ['platform_locked' => false, 'scalars' => ['product.name', 'product.sku', 'warehouse.name', 'stock.available', 'stock.reorder_point'], 'sections' => []],
        'purchase_order.approved' => ['platform_locked' => false, 'scalars' => ['purchase_order.number', 'purchase_order.total', 'partner.name'], 'sections' => []],
        'warranty_claim.submitted' => ['platform_locked' => false, 'scalars' => ['claim.number', 'claim.reason', 'vehicle.registration_number'], 'sections' => []],
        'subscription.expiring' => ['platform_locked' => true, 'scalars' => ['subscription.plan_name', 'subscription.expires_at'], 'sections' => []],
        'invoice.due' => ['platform_locked' => true, 'scalars' => ['invoice.number', 'invoice.due_date', 'invoice.total'], 'sections' => []],
        'subscription.suspended' => ['platform_locked' => true, 'scalars' => ['subscription.plan_name', 'subscription.suspended_at'], 'sections' => []],
        'payment.verification_required' => ['platform_locked' => true, 'scalars' => ['payment.reference', 'payment.amount'], 'sections' => []],
    ];

    private const COMMON_SCALARS = ['tenant.name', 'event_code', 'generated_at'];

    public function isKnownEvent(string $eventCode): bool
    {
        return array_key_exists($eventCode, self::EVENTS);
    }

    public function isPlatformLocked(string $eventCode): bool
    {
        return self::EVENTS[$eventCode]['platform_locked'] ?? false;
    }

    public function eventCodes(): array
    {
        return array_keys(self::EVENTS);
    }

    public function variableDefinition(string $eventCode): array
    {
        if (! $this->isKnownEvent($eventCode)) {
            throw new NotificationException("Unknown notification event '{$eventCode}'.");
        }

        return [
            'scalars' => [...self::COMMON_SCALARS, ...self::EVENTS[$eventCode]['scalars']],
            'sections' => self::EVENTS[$eventCode]['sections'],
        ];
    }
}
