<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 12px; color: #111827; }
    .header { display: flex; justify-content: space-between; margin-bottom: 24px; }
    .brand { font-size: 22px; font-weight: bold; color: #1d4ed8; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
    th { background: #f9fafb; text-transform: uppercase; font-size: 10px; color: #6b7280; }
    .text-right { text-align: right; }
    .totals { width: 280px; margin-left: auto; margin-top: 12px; }
    .totals td { border: none; padding: 4px 8px; }
    .totals .grand { font-weight: bold; font-size: 14px; border-top: 2px solid #111827; }
    .status { display: inline-block; padding: 4px 10px; border-radius: 4px; color: #fff; font-weight: bold; font-size: 11px; }
    .badge-paid { background: #15803d; }
    .badge-outstanding { background: #a16207; }
    .badge-overdue { background: #b91c1c; }
    .badge-void { background: #6b7280; }
</style>
</head>
<body>
    <div class="header">
        <div>
            <div class="brand">OptiFleet</div>
            <div class="muted">Vehicle Maintenance Management Platform</div>
        </div>
        <div style="text-align:right">
            <div style="font-size:16px;font-weight:bold">INVOICE</div>
            <div>{{ $invoice->invoice_number }}</div>
        </div>
    </div>

    <table style="margin-top:0">
        <tr>
            <td style="border:none;width:50%">
                <strong>Bill To</strong><br>
                {{ $invoice->tenant->name }}<br>
                {{ $invoice->tenant->legal_name ?? '' }}<br>
                Tenant Code: {{ $invoice->tenant->code }}
            </td>
            <td style="border:none">
                <strong>Contract:</strong> {{ $invoice->contract->contract_number }}<br>
                <strong>Invoice Date:</strong> {{ $invoice->invoice_date->format('d M Y') }}<br>
                <strong>Due Date:</strong> {{ $invoice->due_date->format('d M Y') }}<br>
                <strong>Status:</strong>
                <span class="status
                    @if($invoice->status === 'PAID') badge-paid
                    @elseif($invoice->status === 'OVERDUE') badge-overdue
                    @elseif($invoice->status === 'VOID') badge-void
                    @else badge-outstanding @endif">
                    {{ $invoice->status }}
                </span>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Discount</th>
                <th class="text-right">Tax</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ number_format($item->discount, 2) }}</td>
                <td class="text-right">{{ number_format($item->tax, 2) }}</td>
                <td class="text-right">{{ number_format($item->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="text-right">{{ $invoice->currency }} {{ number_format($invoice->subtotal, 2) }}</td></tr>
        <tr><td>Discount</td><td class="text-right">- {{ number_format($invoice->discount, 2) }}</td></tr>
        <tr><td>Tax</td><td class="text-right">{{ number_format($invoice->tax, 2) }}</td></tr>
        @if($invoice->adjustment != 0)
        <tr><td>Adjustment</td><td class="text-right">{{ number_format($invoice->adjustment, 2) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="text-right">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</td></tr>
        <tr><td>Paid</td><td class="text-right">{{ number_format($invoice->paid_amount, 2) }}</td></tr>
        <tr><td><strong>Outstanding</strong></td><td class="text-right"><strong>{{ number_format($invoice->outstanding_amount, 2) }}</strong></td></tr>
    </table>

    <p class="muted" style="margin-top:40px;font-size:10px">
        This is a system-generated OptiFleet platform invoice. For questions regarding this invoice, contact your OptiFleet account representative.
    </p>
</body>
</html>
