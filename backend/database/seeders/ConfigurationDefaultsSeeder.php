<?php

namespace Database\Seeders;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Configuration\Services\TemplateValidator;
use App\Domain\Procurement\Support\RfqDocumentTemplate;
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
            // R1: Maintenance Memo previously had no document number at all;
            // Workshop Invoice is new in this batch. Both follow the exact
            // same platform-default-numbering pattern as every other document.
            'maintenance_memo' => ['format' => 'MEMO/OPTIFLEET/{YYYY}/{SEQ:6}', 'doc_code' => 'MEMO', 'reset_rule' => 'YEARLY'],
            // "Next Improvement Tenant Portal - Products" Section 20: Item Code is
            // net-new (no prior hardcoded generator to reproduce) — auto-generated,
            // read-only, and extends this same configurable numbering engine rather
            // than inventing a separate mechanism.
            'product_item' => ['format' => 'ITM/{YYYY}/{SEQ:6}', 'doc_code' => 'ITM', 'reset_rule' => 'YEARLY'],
            // Component Group improvement (owner-approved): Product SKU is server-generated as
            // [Item Type code]-[Component Group abbreviation]-[sequence]; the sequence runs per
            // prefix (DocumentNumberingService context partition) and never resets.
            'product_sku' => ['format' => '{ITEMTYPE}-{CG}-{SEQ:6}', 'doc_code' => 'SKU', 'reset_rule' => 'NEVER'],
            // Return Number of a new part returned (not used) from a Work Order's Issuance & Return.
            'part_return' => ['format' => 'RTN/{YYYY}/{SEQ:6}', 'doc_code' => 'RTN', 'reset_rule' => 'YEARLY'],
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
            // "Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice" Section
            // 6: the Work Authorization Letter follows the source document's own letter layout
            // (TO / VEHICLE INFORMATION / WORK AUTHORIZATION / handover / signature blocks), not
            // the generic $wrap() business-document header+"Prepared by/Approved by" footer used
            // above — a signed letter needs its own two-party signature blocks instead.
            'work_authorization_letter' => <<<'HTML'
                <div style="font-family:sans-serif;font-size:12px;">
                  <h2 style="text-align:center;">WORK AUTHORIZATION LETTER</h2>
                  <p>Authorization No.: {{wal.number}}<br>
                  Work Order No.: {{work_order.number}}<br>
                  Issue Date: {{wal.issue_date}}</p>
                  <p><strong>TO</strong><br>
                  Workshop: {{wal.workshop_name}}<br>
                  Address: {{wal.workshop_address}}<br>
                  Contact Person: {{wal.workshop_pic}}<br>
                  Phone: {{wal.workshop_phone}}</p>
                  <p><strong>VEHICLE INFORMATION</strong><br>
                  Vehicle / Unit No.: {{wal.vehicle_unit_number}}<br>
                  Registration No.: {{wal.vehicle_registration_number}}<br>
                  Make / Model: {{wal.vehicle_make_model}}<br>
                  Current Odometer: {{wal.vehicle_odometer}} km</p>
                  <p><strong>WORK AUTHORIZATION</strong><br>
                  We hereby authorize {{wal.workshop_name}} to perform the maintenance and/or repair work on the vehicle
                  specified above in accordance with Work Order No. {{work_order.number}}.</p>
                  <p>The detailed scope of work, parts, services, and other technical requirements are specified in the
                  associated Work Order delivered together with the vehicle. The workshop is authorized to perform only
                  the work specified in the Work Order. Any additional work, replacement of parts, or costs outside the
                  approved Work Order must obtain prior approval from {{wal.company_name}} before execution. This
                  authorization does not constitute approval for any additional work or charges beyond those specified
                  in the approved Work Order.</p>
                  <p><strong>VEHICLE &amp; WORK ORDER HANDOVER</strong><br>
                  By signing this document, the workshop acknowledges that it has received: the vehicle specified in
                  this authorization; the associated Work Order; and authorization to perform the work specified in the
                  Work Order. The Work Order shall remain with the workshop during the maintenance or repair process.
                  This Work Authorization Letter shall be signed by the workshop representative and returned to
                  {{wal.company_name}} as evidence of receipt and acceptance of the authorized work.</p>
                  <div style="display:flex;justify-content:space-between;margin-top:24px;">
                    <div style="width:45%;">
                      <strong>AUTHORIZED BY</strong><br>{{wal.company_name}}<br><br>
                      Name: ______________________________<br>
                      Position: ___________________________<br>
                      Signature: __________________________<br>
                      Date: _______________________________
                    </div>
                    <div style="width:45%;">
                      <strong>WORKSHOP ACKNOWLEDGEMENT</strong><br>
                      We hereby acknowledge receipt of the vehicle and the associated Work Order and confirm our
                      acceptance to perform the authorized work in accordance with the Work Order.<br><br>
                      Workshop: _________________________<br>
                      Received By: ______________________<br>
                      Position: _________________________<br>
                      Date &amp; Time Received: _____________<br>
                      Signature: ________________________<br>
                      Workshop Stamp:
                    </div>
                  </div>
                  <p style="margin-top:16px;font-size:10px;"><em>Important: Any additional work, parts, or costs not
                  specified in the approved Work Order must receive prior authorization from {{wal.company_name}}
                  before the work is performed.</em></p>
                  <div style="margin-top:8px;font-size:9px;color:#666;">Revision {{wal.revision}} | Template v{{template_version}} | Config v{{configuration_version}}</div>
                </div>
                HTML,
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
            // Printed per invited vendor from RFQ Detail (see RfqDocumentTemplate).
            'rfq' => RfqDocumentTemplate::html(),
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
            'maintenance_memo' => $wrap('Maintenance Memo', <<<'HTML'
                <p>To (Workshop Partner): {{partner.name}}, {{partner.address}}<br>Contact: {{partner.contact_name}} ({{partner.contact_phone}})<br>
                Unit: {{vehicle.registration_number}} ({{vehicle.brand}} {{vehicle.model}}) | Work Order: {{work_order.number}} ({{work_order.maintenance_type}})<br>
                Priority: {{maintenance_memo.priority}} | Status: {{maintenance_memo.status}}</p>
                <p>Requested work: {{maintenance_memo.description}}</p>
                <p>Condition notes: {{maintenance_memo.condition_notes}}</p>
                <p>Estimated cost: {{maintenance_memo.cost}}</p>
                <p>Requested: {{maintenance_memo.requested_at}} | Completed: {{maintenance_memo.completed_at}}</p>
                HTML),
            // R1: this is OptiFleet's own record of a Workshop Invoice the partner issued
            // externally — the printed document is a settlement RECORD, not an
            // OptiFleet-issued invoice; wording is deliberately "Recorded Workshop
            // Invoice", never "Invoice #{{...}}" alone, to avoid implying OptiFleet issued it.
            'workshop_invoice' => $wrap('Recorded Workshop Invoice', <<<'HTML'
                <p>Workshop Partner: {{partner.name}}, {{partner.address}}<br>
                External Invoice No: {{workshop_invoice.external_invoice_number}} | Invoice Date: {{workshop_invoice.invoice_date}} | Due: {{workshop_invoice.due_date}}<br>
                Related Work Order: {{work_order.number}} | Related Memo: {{maintenance_memo.reference_number}}<br>
                Status: {{workshop_invoice.status}}</p>
                <p style="text-align:right;">Subtotal: {{workshop_invoice.subtotal}} | Tax: {{workshop_invoice.tax_total}} | Discount: {{workshop_invoice.discount_total}}<br>
                <strong>Total: {{workshop_invoice.total_amount}} {{workshop_invoice.currency}}</strong></p>
                <p>Notes: {{workshop_invoice.notes}}</p>
                HTML),
        ];
    }
}
