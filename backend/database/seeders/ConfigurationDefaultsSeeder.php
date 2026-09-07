<?php

namespace Database\Seeders;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\TemplateValidator;
use Illuminate\Database\Seeder;

/**
 * Phase 5 Section 52: platform-level default configurations every tenant
 * falls back to until they publish their own override. The numbering
 * defaults reproduce the exact literal formats the old hardcoded
 * generators produced (Section 47: backward compatibility) — new
 * documents created after this seeder runs get identical-looking numbers
 * to before, just generated through the configurable engine. The template
 * defaults are net-new (no built-in printable document existed before
 * Phase 5) but are run through the same TemplateValidator every tenant
 * draft is, so a broken default template fails the seed loudly rather than
 * shipping unusable.
 */
class ConfigurationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ConfigurationService::class);
        $validator = app(TemplateValidator::class);

        $numbering = [
            'work_order' => ['format' => 'WO/OPTIFLEET/{YYYY}/{SEQ:6}', 'doc_code' => 'WO', 'reset_rule' => 'YEARLY'],
            'maintenance_request' => ['format' => 'MR/OPTIFLEET/{YYYY}/{SEQ:6}', 'doc_code' => 'MR', 'reset_rule' => 'YEARLY'],
            'vehicle_transfer' => ['format' => 'VT/{YYYY}/{SEQ:6}', 'doc_code' => 'VT', 'reset_rule' => 'YEARLY'],
            'stock_transfer' => ['format' => 'TRF/{YYYY}/{SEQ:6}', 'doc_code' => 'TRF', 'reset_rule' => 'YEARLY'],
            'purchase_request' => ['format' => 'PR/{YYYY}/{SEQ:6}', 'doc_code' => 'PR', 'reset_rule' => 'YEARLY'],
            'rfq' => ['format' => 'RFQ/{YYYY}/{SEQ:6}', 'doc_code' => 'RFQ', 'reset_rule' => 'YEARLY'],
            'purchase_order' => ['format' => 'PO/{YYYY}/{SEQ:6}', 'doc_code' => 'PO', 'reset_rule' => 'YEARLY'],
            'goods_receipt' => ['format' => 'GR/{YYYY}/{SEQ:6}', 'doc_code' => 'GR', 'reset_rule' => 'YEARLY'],
            'warranty_claim' => ['format' => 'WC/{YYYY}/{SEQ:6}', 'doc_code' => 'WC', 'reset_rule' => 'YEARLY'],
        ];

        foreach ($numbering as $code => $payload) {
            $this->seedPlatformDefault($service, 'NUMBERING', $code, ucwords(str_replace('_', ' ', $code)).' Numbering', $payload);
        }

        foreach ($this->defaultTemplates() as $code => $html) {
            $this->seedPlatformDefault(
                $service, 'TEMPLATE', $code, ucwords(str_replace('_', ' ', $code)).' Template',
                ['html' => $html],
                fn (array $payload) => $validator->validate($code, $payload['html'])
            );
        }
    }

    private function seedPlatformDefault(ConfigurationService $service, string $type, string $code, string $name, array $payload, ?callable $validator = null): void
    {
        $set = $service->findOrCreateSet(null, $type, $code, 'TENANT', null, $name, true);
        if ($set->publishedVersion()) {
            return; // already seeded and published — never overwrite published history.
        }
        $service->publish($service->createDraft($set, $payload, null, 'Initial platform default'), null, $validator);
    }

    private function defaultTemplates(): array
    {
        $wrap = fn (string $title, string $body) => <<<HTML
            <div style="font-family:sans-serif;font-size:12px;">
              <div style="display:flex;justify-content:space-between;border-bottom:2px solid #333;padding-bottom:8px;">
                <div><strong>{{company.name}}</strong><br>{{company.address}}<br>{{company.phone}}</div>
                <div style="text-align:right;"><h2>{$title}</h2>No: {{document_number}}<br>Generated: {{generated_at}}</div>
              </div>
              {$body}
              <div style="margin-top:24px;display:flex;justify-content:space-between;">
                <div>Prepared by: ______________</div>
                <div>Approved by: ______________</div>
              </div>
              <div style="margin-top:8px;font-size:9px;color:#666;">Template v{{template_version}} | Config v{{configuration_version}}</div>
            </div>
            HTML;

        return [
            'work_order' => $wrap('Work Order', <<<'HTML'
                <p>Vehicle: {{vehicle.registration_number}} ({{vehicle.brand}} {{vehicle.model}})<br>
                Branch/Workshop: {{branch.name}} / {{workshop.name}}<br>
                Status: {{work_order.status}} | Type: {{work_order.maintenance_type}} | Priority: {{work_order.priority}}<br>
                Complaint: {{work_order.complaint}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Job</th><th>Status</th><th>Est. Hours</th><th>Actual Hours</th></tr>
                  {{#jobs}}<tr><td>{{description}}</td><td>{{status}}</td><td>{{estimated_hours}}</td><td>{{actual_hours}}</td></tr>{{/jobs}}
                </table>
                HTML),
            'maintenance_report' => $wrap('Maintenance Report', <<<'HTML'
                <p>Vehicle: {{vehicle.registration_number}} ({{vehicle.brand}} {{vehicle.model}})<br>
                Branch/Workshop: {{branch.name}} / {{workshop.name}}<br>
                Request: {{maintenance_request.number}} | Status: {{maintenance_request.status}}<br>
                Complaint: {{maintenance_request.complaint}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Job</th><th>Status</th><th>Actual Hours</th></tr>
                  {{#jobs}}<tr><td>{{description}}</td><td>{{status}}</td><td>{{actual_hours}}</td></tr>{{/jobs}}
                </table>
                HTML),
            'inspection_report' => $wrap('Inspection Report', <<<'HTML'
                <p>Vehicle: {{vehicle.registration_number}} ({{vehicle.brand}} {{vehicle.model}})<br>
                Template: {{inspection.template_name}} | Status: {{inspection.status}} | Inspected: {{inspection.inspected_at}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Item</th><th>Result</th><th>Note</th></tr>
                  {{#findings}}<tr><td>{{item_name}}</td><td>{{result}}</td><td>{{note}}</td></tr>{{/findings}}
                </table>
                HTML),
            'vehicle_transfer' => $wrap('Vehicle Transfer', <<<'HTML'
                <p>Vehicle: {{vehicle.registration_number}} ({{vehicle.brand}} {{vehicle.model}})<br>
                From: {{from_branch.name}} &rarr; To: {{to_branch.name}}<br>
                Status: {{transfer.status}} | Reason: {{transfer.reason}}<br>
                Requested: {{transfer.requested_at}} | Completed: {{transfer.completed_at}}</p>
                HTML),
            'stock_transfer' => $wrap('Stock Transfer', <<<'HTML'
                <p>From: {{from_warehouse.name}} &rarr; To: {{to_warehouse.name}}<br>
                Status: {{transfer.status}} | Dispatched: {{transfer.dispatched_at}} | Received: {{transfer.received_at}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Product</th><th>Sent</th><th>Received</th></tr>
                  {{#items}}<tr><td>{{product_name}}</td><td>{{quantity_sent}}</td><td>{{quantity_received}}</td></tr>{{/items}}
                </table>
                HTML),
            'purchase_request' => $wrap('Purchase Request', <<<'HTML'
                <p>Warehouse: {{warehouse.name}}<br>
                Status: {{purchase_request.status}} | Priority: {{purchase_request.priority}} | Required: {{purchase_request.required_date}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Product</th><th>Qty Requested</th><th>Est. Unit Price</th></tr>
                  {{#items}}<tr><td>{{product_name}}</td><td>{{requested_quantity}}</td><td>{{estimated_unit_price}}</td></tr>{{/items}}
                </table>
                HTML),
            'purchase_order' => $wrap('Purchase Order', <<<'HTML'
                <p>Vendor: {{partner.name}}, {{partner.address}}<br>Contact: {{partner.contact_name}} ({{partner.contact_phone}})<br>
                Delivery Warehouse: {{delivery_warehouse.name}}<br>
                Order Date: {{purchase_order.order_date}} | Expected: {{purchase_order.expected_delivery_date}} | Status: {{purchase_order.status}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Disc%</th><th>Tax%</th><th>Line Total</th></tr>
                  {{#items}}<tr><td>{{product_name}}</td><td>{{quantity_ordered}}</td><td>{{unit_price}}</td><td>{{discount_percent}}</td><td>{{tax_percent}}</td><td>{{line_total}}</td></tr>{{/items}}
                </table>
                <p style="text-align:right;">Subtotal: {{purchase_order.subtotal}} | Tax: {{purchase_order.tax_total}} | Freight: {{purchase_order.freight_cost}}<br>
                <strong>Total: {{purchase_order.total}}</strong></p>
                HTML),
            'goods_receipt' => $wrap('Goods Receipt', <<<'HTML'
                <p>Warehouse: {{warehouse.name}} | Vendor: {{partner.name}}<br>
                Status: {{goods_receipt.status}} | Received: {{goods_receipt.received_at}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Product</th><th>Accepted</th><th>Rejected</th><th>Damaged</th></tr>
                  {{#items}}<tr><td>{{product_name}}</td><td>{{quantity_accepted}}</td><td>{{quantity_rejected}}</td><td>{{quantity_damaged}}</td></tr>{{/items}}
                </table>
                HTML),
            'warranty_claim' => $wrap('Warranty Claim', <<<'HTML'
                <p>Vehicle: {{vehicle.registration_number}} | Vendor: {{partner.name}}<br>
                Status: {{claim.status}} | Amount: {{claim.claim_amount}}<br>
                Failure Date: {{claim.failure_date}} | Settled: {{claim.settled_at}}<br>
                Reason: {{claim.reason}}</p>
                HTML),
            'invoice' => $wrap('Invoice', <<<'HTML'
                <p>Status: {{invoice.status}} | Issue Date: {{invoice.issue_date}} | Due: {{invoice.due_date}}</p>
                <table border="1" cellpadding="4" style="width:100%;border-collapse:collapse;">
                  <tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr>
                  {{#items}}<tr><td>{{description}}</td><td>{{quantity}}</td><td>{{unit_price}}</td><td>{{line_total}}</td></tr>{{/items}}
                </table>
                <p style="text-align:right;">Subtotal: {{invoice.subtotal}} | Tax: {{invoice.tax_total}}<br>
                <strong>Total: {{invoice.total}}</strong></p>
                HTML),
        ];
    }
}
