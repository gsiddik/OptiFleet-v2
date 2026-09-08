<?php

namespace Database\Seeders;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Notification\Models\NotificationRule;
use App\Domain\Notification\Services\NotificationTemplateValidator;
use Illuminate\Database\Seeder;

/**
 * Phase 5 Section 53: converts the platform's built-in Maintenance Due /
 * Critical Breakdown / Low Stock notifications (previously nothing more
 * than a status/flag a user had to notice on their own) into seeded,
 * tenant-overridable platform defaults — plus the maintenance request
 * submission notice this batch also wires live. Rules are tenant_id=null
 * (is_system=true), so they apply to every tenant until that tenant adds
 * its own rule for the same event; unlike numbering/template/workflow,
 * multiple matching rules for one event all fire (additive, not a single
 * "effective" resolution).
 */
class NotificationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTemplates();
        $this->seedRules();
    }

    private function seedTemplates(): void
    {
        $service = app(ConfigurationService::class);
        $validator = app(NotificationTemplateValidator::class);

        $templates = [
            'maintenance.due' => [
                'IN_APP' => ['body' => 'Vehicle {{vehicle.registration_number}} is due for maintenance ({{schedule.policy_name}}) on {{schedule.due_at}}.'],
                'EMAIL' => ['subject' => 'Maintenance due: {{vehicle.registration_number}}', 'body' => 'Vehicle {{vehicle.registration_number}} is due for maintenance ({{schedule.policy_name}}) on {{schedule.due_at}}.'],
            ],
            'breakdown.reported' => [
                'IN_APP' => ['body' => '{{breakdown.severity}} breakdown reported for {{vehicle.registration_number}} at {{breakdown.location}}: {{breakdown.description}}'],
                'EMAIL' => ['subject' => 'Breakdown reported: {{vehicle.registration_number}}', 'body' => '{{breakdown.severity}} breakdown reported for {{vehicle.registration_number}} at {{breakdown.location}}: {{breakdown.description}}'],
            ],
            'inventory.low_stock' => [
                'IN_APP' => ['body' => '{{product.name}} ({{product.sku}}) at {{warehouse.name}} is low: {{stock.available}} available, reorder point {{stock.reorder_point}}.'],
            ],
            'maintenance_request.submitted' => [
                'IN_APP' => ['body' => 'Maintenance request {{request.number}} submitted for {{vehicle.registration_number}}: {{request.complaint}}'],
            ],
            'intelligence.vehicle_critical' => [
                'IN_APP' => ['body' => 'CRITICAL intelligence risk on {{entity_type}} {{entity_id}}: {{recommendation_type}} recommended.'],
            ],
            'intelligence.vehicle_high_risk' => [
                'IN_APP' => ['body' => 'HIGH intelligence risk on {{entity_type}} {{entity_id}}: {{recommendation_type}} recommended.'],
            ],
        ];

        foreach ($templates as $eventCode => $channels) {
            $set = $service->findOrCreateSet(null, 'NOTIFICATION', $eventCode, 'TENANT', null, ucwords(str_replace(['_', '.'], ' ', $eventCode)).' Notification', true);
            if ($set->publishedVersion()) {
                continue;
            }
            $payload = ['channels' => $channels];
            $service->publish(
                $service->createDraft($set, $payload, null, 'Initial platform default'),
                null,
                fn (array $p) => $validator->validate($eventCode, $p)
            );
        }
    }

    private function seedRules(): void
    {
        $rules = [
            [
                'event_code' => 'breakdown.reported',
                'name' => 'Critical breakdown -> Branch Manager (escalates to Fleet Manager)',
                'condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'breakdown.severity', 'op' => 'IN', 'value' => ['CRITICAL', 'IMMOBILIZED']]]],
                'recipient_rules' => [['type' => 'BRANCH_MANAGER']],
                'channels' => ['IN_APP', 'EMAIL'],
                'escalation' => [
                    'after_minutes' => 120,
                    'recipient_rules' => [['type' => 'ROLE', 'identifier' => 'Fleet Manager']],
                    'unresolved_condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'status', 'op' => '!=', 'value' => 'RESOLVED']]],
                ],
            ],
            [
                'event_code' => 'maintenance.due',
                'name' => 'Maintenance due -> Fleet Manager',
                'condition_set' => null,
                'recipient_rules' => [['type' => 'ROLE', 'identifier' => 'Fleet Manager']],
                'channels' => ['IN_APP'],
                'escalation' => null,
            ],
            [
                'event_code' => 'inventory.low_stock',
                'name' => 'Low stock -> Purchase Request creators',
                'condition_set' => null,
                'recipient_rules' => [['type' => 'PERMISSION', 'identifier' => 'purchase_request.create']],
                'channels' => ['IN_APP'],
                'escalation' => null,
            ],
            [
                'event_code' => 'maintenance_request.submitted',
                'name' => 'Maintenance request submitted -> Reviewers',
                'condition_set' => null,
                'recipient_rules' => [['type' => 'PERMISSION', 'identifier' => 'maintenance_request.review']],
                'channels' => ['IN_APP'],
                'escalation' => null,
            ],
            [
                'event_code' => 'intelligence.vehicle_critical',
                'name' => 'Intelligence critical risk -> Fleet Manager',
                'condition_set' => null,
                'recipient_rules' => [['type' => 'ROLE', 'identifier' => 'Fleet Manager']],
                'channels' => ['IN_APP'],
                'escalation' => null,
            ],
            [
                'event_code' => 'intelligence.vehicle_high_risk',
                'name' => 'Intelligence high risk -> Fleet Manager',
                'condition_set' => null,
                'recipient_rules' => [['type' => 'ROLE', 'identifier' => 'Fleet Manager']],
                'channels' => ['IN_APP'],
                'escalation' => null,
            ],
        ];

        foreach ($rules as $rule) {
            $exists = NotificationRule::query()->withoutGlobalScopes()
                ->whereNull('tenant_id')->where('event_code', $rule['event_code'])->where('is_system', true)->exists();
            if ($exists) {
                continue;
            }

            NotificationRule::query()->create([
                'tenant_id' => null,
                'event_code' => $rule['event_code'],
                'name' => $rule['name'],
                'is_active' => true,
                'is_system' => true,
                'condition_set' => $rule['condition_set'],
                'recipient_rules' => $rule['recipient_rules'],
                'channels' => $rule['channels'],
                'escalation' => $rule['escalation'],
            ]);
        }
    }
}
