@php
    // i18n: labels, status, dates and amounts follow the document locale; invoice data is printed as stored.
    $locale = $locale ?? 'en';
    $L = fn (string $key, array $params = []) => \App\Domain\Shared\Support\Messages::text('documents.platformInvoice.'.$key, $params, $locale);
    $date = fn ($value) => \App\Domain\Shared\Support\DisplayFormat::date($value, $locale);
    $money = fn ($value) => \App\Domain\Shared\Support\DisplayFormat::money($value, $locale);
    $qty = fn ($value) => \App\Domain\Shared\Support\DisplayFormat::quantity($value, $locale);
    $statusLabel = fn (?string $code) => \App\Domain\Shared\Support\StatusLabels::localized($code, $locale, 'document');
@endphp
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
            <div class="brand">{{ $L('optiFleet') }}</div>
            <div class="muted">{{ $L('vehicleMaintenanceManagementPlatform') }}</div>
        </div>
        <div style="text-align:right">
            <div style="font-size:16px;font-weight:bold">{{ $L('invoice') }}</div>
            <div>{{ $invoice->invoice_number }}</div>
        </div>
    </div>

    <table style="margin-top:0">
        <tr>
            <td style="border:none;width:50%">
                <strong>{{ $L('billTo') }}</strong><br>
                {{ $invoice->tenant->name }}<br>
                {{ $invoice->tenant->legal_name ?? '' }}<br>
                {{ $L('tenantCodeCode', ['code' => $invoice->tenant->code]) }}
            </td>
            <td style="border:none">
                <strong>{{ $L('contract') }}:</strong> {{ $invoice->contract->contract_number }}<br>
                <strong>{{ $L('invoiceDate') }}:</strong> {{ $date($invoice->invoice_date) }}<br>
                <strong>{{ $L('dueDate') }}:</strong> {{ $date($invoice->due_date) }}<br>
                <strong>{{ $L('status') }}:</strong>
                <span class="status
                    @if($invoice->status === 'PAID') badge-paid
                    @elseif($invoice->status === 'OVERDUE') badge-overdue
                    @elseif($invoice->status === 'VOID') badge-void
                    @else badge-outstanding @endif">
                    {{ $statusLabel($invoice->status) }}
                </span>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>{{ $L('description') }}</th>
                <th class="text-right">{{ $L('qty') }}</th>
                <th class="text-right">{{ $L('unitPrice') }}</th>
                <th class="text-right">{{ $L('discount') }}</th>
                <th class="text-right">{{ $L('tax') }}</th>
                <th class="text-right">{{ $L('amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ $qty($item->quantity) }}</td>
                <td class="text-right">{{ $money($item->unit_price) }}</td>
                <td class="text-right">{{ $money($item->discount) }}</td>
                <td class="text-right">{{ $money($item->tax) }}</td>
                <td class="text-right">{{ $money($item->amount) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ $L('subtotal') }}</td><td class="text-right">{{ $invoice->currency }} {{ $money($invoice->subtotal) }}</td></tr>
        <tr><td>{{ $L('discount') }}</td><td class="text-right">- {{ $money($invoice->discount) }}</td></tr>
        <tr><td>{{ $L('tax') }}</td><td class="text-right">{{ $money($invoice->tax) }}</td></tr>
        @if($invoice->adjustment != 0)
        <tr><td>{{ $L('adjustment') }}</td><td class="text-right">{{ $money($invoice->adjustment) }}</td></tr>
        @endif
        <tr class="grand"><td>{{ $L('total') }}</td><td class="text-right">{{ $invoice->currency }} {{ $money($invoice->total) }}</td></tr>
        <tr><td>{{ $L('paid') }}</td><td class="text-right">{{ $money($invoice->paid_amount) }}</td></tr>
        <tr><td><strong>{{ $L('outstanding') }}</strong></td><td class="text-right"><strong>{{ $money($invoice->outstanding_amount) }}</strong></td></tr>
    </table>

    <p class="muted" style="margin-top:40px;font-size:10px">
        {{ $L('systemGeneratedOptiFleetPlatformInvoice') }}
    </p>
</body>
</html>
