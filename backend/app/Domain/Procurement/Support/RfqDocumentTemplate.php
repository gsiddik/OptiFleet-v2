<?php

namespace App\Domain\Procurement\Support;

/**
 * Default "Request for Quotation" document, one per invited vendor. Published as the platform
 * default `rfq` template by ConfigurationDefaultsSeeder; also used as the fallback when a database
 * has not been re-seeded yet, so vendor printing always works. Tenants may publish their own
 * version through Document Templates (only TemplateVariableRegistry 'rfq' variables).
 */
final class RfqDocumentTemplate
{
    public static function html(): string
    {
        return <<<'HTML'
            <div style="font-family:sans-serif;font-size:12px;">
              <div style="display:flex;justify-content:space-between;border-bottom:2px solid #333;padding-bottom:8px;">
                <div><strong>{{company.name}}</strong><br>{{tenant.name}}<br>{{company.address}}<br>{{company.phone}}</div>
                <div style="text-align:right;"><h2 style="margin:0 0 4px;">Request for Quotation</h2>RFQ No: <strong>{{rfq.number}}</strong><br>RFQ Date: {{rfq.issue_date}}<br>Response by: {{rfq.response_deadline}}</div>
              </div>
              <p style="margin-top:14px;">To:<br><strong>{{vendor.name}}</strong><br>{{vendor.address}}<br>Attn: {{vendor.contact_name}} {{vendor.contact_phone}}</p>
              <p>Deliver to warehouse: <strong>{{warehouse.name}}</strong><br>{{warehouse.address}}</p>
              <p>Dear {{vendor.name}},<br>
              We kindly request your quotation for the items listed below. Please state your <strong>unit price</strong> for each item
              and the <strong>estimated delivery time (lead time, in days) after you receive our Purchase Order</strong>.</p>
              <table border="1" cellpadding="5" style="width:100%;border-collapse:collapse;">
                <tr style="background:#f3f4f6;"><th>No</th><th>Code</th><th>Item</th><th>Qty</th><th>UOM</th><th>Unit Price</th><th>Lead Time (days)</th></tr>
                {{#items}}<tr><td>{{line_no}}</td><td>{{product_code}}</td><td>{{product_name}}</td><td style="text-align:right;">{{quantity}}</td><td>{{uom}}</td><td></td><td></td></tr>{{/items}}
              </table>
              <p>Please send your quotation, referring to RFQ No {{rfq.number}}.</p>
              <div style="margin-top:36px;width:260px;">
                <div>Issued by,</div>
                <div style="height:60px;"></div>
                <div style="border-top:1px solid #333;padding-top:4px;"><strong>{{printed_by.name}}</strong><br>{{tenant.name}}<br>Printed: {{printed_at}}</div>
              </div>
              <div style="margin-top:8px;font-size:9px;color:#666;">Template v{{template_version}} | Generated {{generated_at}}</div>
            </div>
            HTML;
    }
}
