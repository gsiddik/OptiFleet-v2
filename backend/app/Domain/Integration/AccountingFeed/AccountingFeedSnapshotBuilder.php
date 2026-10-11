<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Turns one integration outbox row into the canonical accounting event (contract: docs/integration/ACCOUNTING_FEED.md).
 *
 * The outbox payload is deliberately thin, so the facts accounting needs (vendor, invoice dates, subtotal/tax/total,
 * lines, payment details) are read from the source rows. This runs in the same transaction that wrote the outbox row,
 * so the snapshot is the state at event time and is stored; a later correction never rewrites an earlier event.
 *
 * Every read is explicitly scoped by the outbox row's tenant_id and uses the query builder, so no ambient tenant
 * context and no model scope can widen or narrow it. Money is a decimal string with 4 decimals (HALF_UP), never a float.
 */
class AccountingFeedSnapshotBuilder
{
    public const SCHEMA_VERSION = '1.0';

    public const SOURCE_SYSTEM = 'optifleet';

    /** Outbox event types that are part of the feed (informational ones are flagged in their payload). */
    public const EVENT_TYPES = [
        'workshop_invoice.recorded', 'workshop_invoice.corrected', 'workshop_invoice.cancelled', 'workshop_invoice.payment_recorded',
        'maintenance_memo.billed', 'maintenance_memo.paid',
    ];

    private const MONEY_FIELDS = ['subtotal', 'discount_total', 'tax_total', 'total_amount'];

    private const DATE_FIELDS = ['invoice_date', 'due_date'];

    /**
     * Null when the row is not a feed event type or its source rows no longer exist.
     *
     * @return array{event_type: string, occurred_at: Carbon, correlation_id: string, source: array, dimensions: array, payload: array}|null
     */
    public function build(IntegrationOutboxEvent $row, string $basis = 'event_time'): ?array
    {
        if (! in_array($row->event_type, self::EVENT_TYPES, true)) {
            return null;
        }

        $tenantId = (string) $row->tenant_id;
        $correlation = (array) $row->correlation;
        $memoEvent = str_starts_with($row->event_type, 'maintenance_memo.');

        $invoiceId = $row->event_type === 'workshop_invoice.recorded' ? $row->aggregate_id : ($correlation['workshop_invoice_id'] ?? null);
        $invoice = $invoiceId ? $this->find('workshop_invoices', $tenantId, $invoiceId) : null;
        $memo = $memoEvent ? $this->find('work_order_external_services', $tenantId, $row->aggregate_id) : null;
        $workOrderId = $memo->work_order_id ?? $invoice->work_order_id ?? ($correlation['work_order_id'] ?? null);

        if ($memoEvent ? $memo === null : $invoice === null) {
            return null;
        }

        if ($memoEvent) {
            $source = ['type' => 'maintenance_memo', 'id' => $memo->id, 'number' => $memo->memo_number];
            $payload = $this->memoPayload($row, $tenantId, $memo, $invoice);
        } else {
            $memoOfInvoice = $this->find('work_order_external_services', $tenantId, $invoice->work_order_external_service_id);
            $source = ['type' => 'workshop_invoice', 'id' => $invoice->id, 'number' => $invoice->external_invoice_number];
            $payload = $this->invoicePayload($tenantId, $invoice, $memoOfInvoice) + $this->eventDetail($row, $tenantId);
        }

        return [
            'event_type' => self::SOURCE_SYSTEM.'.'.$row->event_type,
            'occurred_at' => Carbon::parse($row->created_at)->utc(),
            'correlation_id' => (string) ($correlation['work_order_id'] ?? $row->aggregate_id),
            'source' => $source,
            'dimensions' => $this->dimensions($tenantId, $workOrderId),
            'payload' => $payload + ['snapshot_basis' => $basis],
        ];
    }

    /** @return array<string, mixed> */
    private function invoicePayload(string $tenantId, stdClass $invoice, ?stdClass $memo): array
    {
        return [
            'vendor' => $this->vendor($tenantId, $invoice->partner_id),
            'invoice_number' => $invoice->external_invoice_number,
            'invoice_date' => $this->date($invoice->invoice_date),
            'due_date' => $this->date($invoice->due_date),
            'currency' => $invoice->currency,
            'subtotal' => $this->money($invoice->subtotal),
            'discount_total' => $this->money($invoice->discount_total),
            'tax_total' => $this->money($invoice->tax_total),
            'total_amount' => $this->money($invoice->total_amount),
            'partner_reference' => $invoice->partner_reference,
            'lines' => $this->lines(json_decode((string) $invoice->line_items, true)),
            'maintenance_memo' => $memo ? ['id' => $memo->id, 'number' => $memo->memo_number] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function eventDetail(IntegrationOutboxEvent $row, string $tenantId): array
    {
        switch ($row->event_type) {
            case 'workshop_invoice.corrected':
                $correction = $this->find('workshop_invoice_corrections', $tenantId, $row->aggregate_id);

                return ['correction' => [
                    'id' => $row->aggregate_id,
                    'reason' => $correction->reason ?? null,
                    'previous_values' => $this->changedFields(json_decode((string) ($correction->previous_values ?? '{}'), true)),
                    'requested_values' => $this->changedFields(json_decode((string) ($correction->requested_values ?? '{}'), true)),
                ]];
            case 'workshop_invoice.cancelled':
                $cancellation = $this->find('workshop_invoice_cancellations', $tenantId, $row->aggregate_id);

                return ['cancellation' => [
                    'id' => $row->aggregate_id,
                    'reason' => $cancellation->reason ?? ($row->payload['reason'] ?? null),
                    'had_payment' => (bool) ($row->payload['had_payment'] ?? false),
                ]];
            case 'workshop_invoice.payment_recorded':
                $payment = $this->find('workshop_invoice_payments', $tenantId, $row->aggregate_id);

                return ['payment' => [
                    'id' => $row->aggregate_id,
                    'amount' => $this->money($payment->paid_amount ?? ($row->payload['paid_amount'] ?? null)),
                    'payment_date' => $this->date($payment->payment_date ?? ($row->payload['payment_date'] ?? null)),
                    'method' => $payment->payment_method ?? null,
                    'reference_number' => $payment->reference_number ?? null,
                ]];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function memoPayload(IntegrationOutboxEvent $row, string $tenantId, stdClass $memo, ?stdClass $invoice): array
    {
        return [
            'informational' => true,
            'memo_number' => $memo->memo_number,
            'memo_cost' => $this->money($memo->cost),
            'currency' => $invoice->currency ?? null,
            'vendor' => $this->vendor($tenantId, $memo->partner_id),
            'workshop_invoice' => $invoice ? ['id' => $invoice->id, 'number' => $invoice->external_invoice_number] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function dimensions(string $tenantId, ?string $workOrderId): array
    {
        $workOrder = $workOrderId ? $this->find('work_orders', $tenantId, $workOrderId) : null;
        $vehicle = $workOrder?->vehicle_id ? $this->find('vehicles', $tenantId, $workOrder->vehicle_id) : null;
        $branch = $workOrder?->branch_id ? $this->find('branches', $tenantId, $workOrder->branch_id) : null;

        return [
            'vehicle' => $vehicle?->id,
            'vehicle_registration_number' => $vehicle?->registration_number,
            'work_order' => $workOrder?->id,
            'work_order_number' => $workOrder?->wo_number,
            'branch' => $branch?->id,
            'branch_code' => $branch?->code,
        ];
    }

    /** @return array<string, mixed>|null */
    private function vendor(string $tenantId, ?string $partnerId): ?array
    {
        $partner = $partnerId ? $this->find('partners', $tenantId, $partnerId) : null;

        return $partner ? ['id' => $partner->id, 'code' => $partner->code, 'name' => $partner->name, 'tax_id' => $partner->tax_id] : null;
    }

    /**
     * Correction diffs hold only the changed fields, in the same names and formats as the invoice facts.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    private function changedFields(?array $values): array
    {
        $out = [];
        foreach ((array) $values as $field => $value) {
            if ($field === 'external_invoice_number') {
                $out['invoice_number'] = $value;
            } elseif (in_array($field, self::MONEY_FIELDS, true)) {
                $out[$field] = $this->money($value);
            } elseif (in_array($field, self::DATE_FIELDS, true)) {
                $out[$field] = $this->date($value);
            } elseif ($field === 'line_items') {
                $out['lines'] = $this->lines($value);
            } elseif (in_array($field, ['currency', 'partner_reference'], true)) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<int, array<string, mixed>>
     */
    private function lines(?array $items): array
    {
        return array_values(array_map(fn ($item) => [
            'description' => $item['description'] ?? null,
            'quantity' => $this->money($item['quantity'] ?? null),
            'unit_price' => $this->money($item['unit_price'] ?? null),
            'line_total' => $this->money($item['line_total'] ?? null),
        ], (array) $items));
    }

    private function find(string $table, string $tenantId, ?string $id): ?stdClass
    {
        return $id ? DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->first() : null;
    }

    private function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) BigDecimal::of((string) $value)->toScale(4, RoundingMode::HALF_UP);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A stored DATE already is the accounting date; never shift it through a timezone conversion.
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m) ? $m[1] : Carbon::parse($value)->toDateString();
    }
}
