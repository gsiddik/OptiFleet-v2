<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Shared\Support\DisplayFormat;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use App\Domain\WorkOrder\Models\WorkshopInvoice;

/**
 * Section 13/51: the only place a live Eloquent document is turned into a
 * template context array. Deliberately whitelists exactly the fields
 * TemplateVariableRegistry allows for the matching document type — never
 * ->toArray() or a raw model — so a published template can never reach a
 * column this builder did not choose to expose ("no unrestricted DB
 * access").
 */
class DocumentTemplateContextBuilder
{
    public static function forWorkOrder(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing(['vehicle', 'branch', 'workshop', 'jobs', 'findings']);
        $tenant = Tenant::query()->find($workOrder->tenant_id);
        // Consolidated External Workshop business rules: printing an External Work Order must
        // show only Findings that have been finalized (i.e. the Work Order has actually reached
        // EXTERNAL at least once) and never internal Jobs/Diagnosis content — the template's
        // {{#is_external}}/{{^is_external}} sections branch on this single flag.
        $isExternal = $workOrder->execution_mode === 'EXTERNAL';

        return [
            'company' => self::company(),
            'tenant' => ['name' => $tenant?->name, 'code' => $tenant?->code],
            'document_number' => $workOrder->wo_number,
            'configuration_version' => $workOrder->numbering_configuration_version_id,
            'is_external' => $isExternal,
            'work_order' => [
                'number' => $workOrder->wo_number,
                'status' => $workOrder->status,
                'maintenance_type' => $workOrder->maintenance_type,
                'priority' => $workOrder->priority,
                'complaint' => $workOrder->complaint,
                'created_at' => optional($workOrder->created_at)->toDateTimeString(),
                'started_at' => optional($workOrder->started_at)->toDateTimeString(),
                'completed_at' => optional($workOrder->completed_at)->toDateTimeString(),
                'revision' => $workOrder->external_finalized_revision,
            ],
            'vehicle' => [
                'registration_number' => $workOrder->vehicle?->registration_number,
                'brand' => $workOrder->vehicle?->brand,
                'model' => $workOrder->vehicle?->model,
                'vin' => $workOrder->vehicle?->vin,
            ],
            'branch' => ['name' => $workOrder->branch?->name],
            'workshop' => ['name' => $workOrder->workshop?->name],
            'jobs' => $workOrder->jobs->map(fn ($job) => [
                'description' => $job->description,
                'status' => $job->status,
                'estimated_hours' => (string) $job->estimated_hours,
                'actual_hours' => (string) $job->actual_hours,
            ])->all(),
            'findings' => $workOrder->findings->map(fn ($finding) => [
                'severity' => $finding->severity,
                'description' => $finding->description,
                'status' => $finding->status,
            ])->all(),
        ];
    }

    /**
     * Section 6: renders ONLY from the frozen snapshot columns on the invoice row itself — never
     * from the live Partner/Vehicle/Tenant it was generated against — so a later change to any
     * of that master data can never alter an already-issued Work Authorization Letter.
     */
    public static function forWorkAuthorizationLetter(WorkOrderExternalInvoice $invoice): array
    {
        $invoice->loadMissing('workOrder');

        return [
            'company' => self::company(),
            'tenant' => ['name' => $invoice->wal_company_name],
            'document_number' => $invoice->wal_number,
            'configuration_version' => null,
            'wal' => [
                'number' => $invoice->wal_number,
                'issue_date' => optional($invoice->wal_issue_date)->toDateString(),
                'workshop_name' => $invoice->wal_workshop_name,
                'workshop_address' => $invoice->wal_workshop_address,
                'workshop_pic' => $invoice->wal_workshop_pic,
                'workshop_phone' => $invoice->wal_workshop_phone,
                'vehicle_unit_number' => $invoice->wal_vehicle_unit_number,
                'vehicle_registration_number' => $invoice->wal_vehicle_registration_number,
                'vehicle_make_model' => $invoice->wal_vehicle_make_model,
                'vehicle_odometer' => $invoice->wal_vehicle_odometer !== null ? (string) $invoice->wal_vehicle_odometer : null,
                'company_name' => $invoice->wal_company_name,
                'revision' => $invoice->wal_revision,
            ],
            'work_order' => ['number' => $invoice->workOrder?->wo_number],
        ];
    }

    public static function forPurchaseOrder(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing(['partner', 'deliveryWarehouse', 'items.product']);
        $tenant = Tenant::query()->find($purchaseOrder->tenant_id);

        return [
            'company' => self::company(),
            'tenant' => ['name' => $tenant?->name, 'code' => $tenant?->code],
            'document_number' => $purchaseOrder->po_number,
            'configuration_version' => $purchaseOrder->numbering_configuration_version_id,
            'purchase_order' => [
                'number' => $purchaseOrder->po_number,
                'status' => $purchaseOrder->status,
                'order_date' => optional($purchaseOrder->order_date)->toDateString(),
                'expected_delivery_date' => optional($purchaseOrder->expected_delivery_date)->toDateString(),
                'subtotal' => DisplayFormat::money($purchaseOrder->subtotal),
                'tax_total' => DisplayFormat::money($purchaseOrder->tax_total),
                'freight_cost' => DisplayFormat::money($purchaseOrder->freight_cost),
                'total' => DisplayFormat::money($purchaseOrder->total),
            ],
            'partner' => [
                'name' => $purchaseOrder->partner?->name,
                'address' => $purchaseOrder->partner?->address,
                'contact_name' => $purchaseOrder->partner?->contact_name,
                'contact_phone' => $purchaseOrder->partner?->contact_phone,
            ],
            'delivery_warehouse' => ['name' => $purchaseOrder->deliveryWarehouse?->name],
            'items' => $purchaseOrder->items->map(fn ($item) => [
                'product_name' => $item->product?->name,
                'quantity_ordered' => DisplayFormat::quantity($item->quantity_ordered),
                'unit_price' => DisplayFormat::money($item->unit_price),
                'discount_percent' => (string) $item->discount_percent,
                'tax_percent' => (string) $item->tax_percent,
                'line_total' => DisplayFormat::money($item->line_total),
            ])->all(),
        ];
    }

    /**
     * RFQ printed for ONE invited vendor: the document names that vendor and asks for unit prices
     * and the delivery lead time after the Purchase Order is received.
     */
    public static function forRfqVendor(Rfq $rfq, Partner $vendor, ?string $printedByName): array
    {
        $rfq->loadMissing(['warehouse', 'items.product.uom']);
        $tenant = Tenant::query()->find($rfq->tenant_id);

        return [
            'company' => self::company(),
            'tenant' => ['name' => $tenant?->name, 'code' => $tenant?->code],
            'document_number' => $rfq->rfq_number,
            'configuration_version' => $rfq->numbering_configuration_version_id,
            'rfq' => [
                'number' => $rfq->rfq_number,
                'status' => $rfq->status,
                'issue_date' => optional($rfq->issue_date)->toDateString() ?? now()->toDateString(),
                'response_deadline' => optional($rfq->response_deadline)->toDateString(),
            ],
            'vendor' => [
                'name' => $vendor->name,
                'address' => $vendor->address,
                'contact_name' => $vendor->contact_name,
                'contact_phone' => $vendor->contact_phone,
                'contact_email' => $vendor->contact_email,
            ],
            'warehouse' => ['name' => $rfq->warehouse?->name, 'address' => $rfq->warehouse?->address],
            'printed_by' => ['name' => $printedByName],
            'printed_at' => now()->toDateTimeString(),
            'items' => $rfq->items->values()->map(fn ($item, $i) => [
                'line_no' => (string) ($i + 1),
                'product_code' => $item->product?->sku ?? $item->product?->code,
                'product_name' => $item->product?->name,
                'quantity' => DisplayFormat::quantity($item->quantity),
                'uom' => $item->product?->uom?->code,
            ])->all(),
        ];
    }

    /**
     * Final reconciliation (queued ADJUST): VMS's Maintenance Memo "Save
     * and Print" — maps to WorkOrderExternalService (the outsourced-work
     * record sent to a Workshop Partner), not the unrelated `MaintenanceRequest`
     * domain that already owns the pre-existing (and separately unused)
     * 'maintenance_report' template type.
     */
    public static function forMaintenanceMemo(WorkOrderExternalService $service): array
    {
        $service->loadMissing(['partner', 'workOrder.vehicle']);
        $tenant = Tenant::query()->find($service->tenant_id);
        $workOrder = $service->workOrder;

        return [
            'company' => self::company(),
            'tenant' => ['name' => $tenant?->name, 'code' => $tenant?->code],
            'document_number' => $service->reference_number ?? $service->id,
            'configuration_version' => null,
            'maintenance_memo' => [
                'reference_number' => $service->reference_number,
                'description' => $service->description,
                'condition_notes' => $service->condition_notes,
                'priority' => $service->priority,
                'status' => $service->status,
                'cost' => DisplayFormat::money($service->cost),
                'requested_at' => optional($service->requested_at)->toDateTimeString(),
                'completed_at' => optional($service->completed_at)->toDateTimeString(),
            ],
            'partner' => [
                'name' => $service->partner?->name,
                'address' => $service->partner?->address,
                'contact_name' => $service->partner?->contact_name,
                'contact_phone' => $service->partner?->contact_phone,
            ],
            'work_order' => [
                'number' => $workOrder?->wo_number,
                'maintenance_type' => $workOrder?->maintenance_type,
            ],
            'vehicle' => [
                'registration_number' => $workOrder?->vehicle?->registration_number,
                'brand' => $workOrder?->vehicle?->brand,
                'model' => $workOrder?->vehicle?->model,
            ],
        ];
    }

    /**
     * R1: renders OptiFleet's own RECORD of an externally-issued Workshop
     * Invoice — never a document that claims OptiFleet issued the invoice.
     */
    public static function forWorkshopInvoice(WorkshopInvoice $invoice): array
    {
        $invoice->loadMissing(['partner', 'workOrder', 'memo']);
        $tenant = Tenant::query()->find($invoice->tenant_id);
        $memo = $invoice->memo;

        return [
            'company' => self::company(),
            'tenant' => ['name' => $tenant?->name, 'code' => $tenant?->code],
            'document_number' => $invoice->external_invoice_number,
            'configuration_version' => null,
            'workshop_invoice' => [
                'external_invoice_number' => $invoice->external_invoice_number,
                'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'currency' => $invoice->currency,
                'subtotal' => DisplayFormat::money($invoice->subtotal),
                'tax_total' => DisplayFormat::money($invoice->tax_total),
                'discount_total' => DisplayFormat::money($invoice->discount_total),
                'total_amount' => DisplayFormat::money($invoice->total_amount),
                'status' => $invoice->status,
                'notes' => $invoice->notes,
                'reconciliation_note' => $invoice->reconciliation_note,
            ],
            'partner' => [
                'name' => $invoice->partner?->name,
                'address' => $invoice->partner?->address,
                'contact_name' => $invoice->partner?->contact_name,
                'contact_phone' => $invoice->partner?->contact_phone,
            ],
            'work_order' => ['number' => $invoice->workOrder?->wo_number],
            'maintenance_memo' => ['reference_number' => $memo?->reference_number, 'description' => $memo?->description],
            'items' => collect($invoice->line_items ?? [])->map(fn ($item) => [
                'description' => $item['description'] ?? '',
                'quantity' => DisplayFormat::quantity($item['quantity'] ?? null),
                'unit_price' => DisplayFormat::money($item['unit_price'] ?? null),
                'line_total' => DisplayFormat::money($item['line_total'] ?? null),
            ])->all(),
        ];
    }

    private static function company(): array
    {
        return [
            'name' => config('app.name', 'OptiFleet'),
            'legal_name' => config('app.name', 'OptiFleet'),
            'address' => '',
            'phone' => '',
            'email' => '',
        ];
    }
}
