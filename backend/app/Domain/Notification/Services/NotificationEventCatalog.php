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

        // Phase 7 Section 35 — reuses this same catalog/rule/template
        // infrastructure, never a second notification system.
        'intelligence.vehicle_high_risk' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.vehicle_critical' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.component_high_risk' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.predicted_failure' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.rul_low' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.repeat_failure' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.anomaly_detected' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
        'intelligence.inventory_shortage_risk' => ['platform_locked' => false, 'scalars' => ['entity_type', 'entity_id', 'risk_level', 'recommendation_type'], 'sections' => []],
    ];

    private const COMMON_SCALARS = ['tenant.name', 'event_code', 'generated_at'];

    /** Display names for the configuration screens (the event codes stay the contract). */
    private const LABELS = [
        'maintenance.due' => 'Maintenance Due',
        'maintenance_request.submitted' => 'Maintenance Request Submitted',
        'work_order.created' => 'Work Order Created',
        'breakdown.reported' => 'Breakdown Reported',
        'inventory.low_stock' => 'Low Stock',
        'purchase_order.approved' => 'Purchase Order Approved',
        'warranty_claim.submitted' => 'Warranty Claim Submitted',
        'subscription.expiring' => 'Subscription Expiring',
        'invoice.due' => 'Subscription Invoice Due',
        'subscription.suspended' => 'Subscription Suspended',
        'payment.verification_required' => 'Payment Verification Required',
        'intelligence.vehicle_high_risk' => 'Vehicle High Risk',
        'intelligence.vehicle_critical' => 'Vehicle Critical Risk',
        'intelligence.component_high_risk' => 'Component High Risk',
        'intelligence.predicted_failure' => 'Predicted Failure',
        'intelligence.rul_low' => 'Low Remaining Useful Life',
        'intelligence.repeat_failure' => 'Repeat Failure',
        'intelligence.anomaly_detected' => 'Anomaly Detected',
        'intelligence.inventory_shortage_risk' => 'Inventory Shortage Risk',
    ];

    /** Variable display names that a plain "Vehicle Registration Number" style label would get wrong. */
    private const VARIABLE_LABELS = [
        'event_code' => 'Event Code',
        'generated_at' => 'Sent At',
        'tenant.name' => 'Company Name',
        'request.number' => 'Maintenance Request Number',
        'request.priority' => 'Maintenance Request Priority',
        'request.complaint' => 'Maintenance Request Complaint',
        'claim.number' => 'Warranty Claim Number',
        'claim.reason' => 'Warranty Claim Reason',
        'partner.name' => 'Vendor Name',
        'stock.available' => 'Available Stock',
        'stock.reorder_point' => 'Reorder Point',
        'product.sku' => 'Product SKU',
    ];

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

    public function label(string $eventCode): string
    {
        return self::LABELS[$eventCode] ?? ucwords(str_replace(['.', '_'], ' ', $eventCode));
    }

    public function variableLabel(string $path): string
    {
        return self::VARIABLE_LABELS[$path] ?? ucwords(str_replace(['.', '_'], ' ', $path));
    }

    /**
     * The event's variables with display names — what the message editor offers and what a
     * rule condition may compare (dispatch passes exactly this context to ConditionEvaluator).
     *
     * @return list<array{key: string, label: string}>
     */
    public function variables(string $eventCode): array
    {
        return array_map(fn (string $path) => ['key' => $path, 'label' => $this->variableLabel($path)], $this->variableDefinition($eventCode)['scalars']);
    }

    /** Preview context: every variable filled with a readable sample ("[Vehicle Registration Number]"). */
    public function sampleContext(string $eventCode): array
    {
        $context = [];
        foreach ($this->variableDefinition($eventCode)['scalars'] as $path) {
            data_set($context, $path, '['.$this->variableLabel($path).']');
        }

        return $context;
    }
}
