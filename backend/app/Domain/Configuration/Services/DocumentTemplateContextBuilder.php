<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\WorkOrder\Models\WorkOrder;
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
        $workOrder->loadMissing(['vehicle', 'branch', 'workshop', 'jobs']);
        $tenant = Tenant::query()->find($workOrder->tenant_id);

        return [
            'company' => self::company(),
            'tenant' => ['name' => $tenant?->name, 'code' => $tenant?->code],
            'document_number' => $workOrder->wo_number,
            'configuration_version' => $workOrder->numbering_configuration_version_id,
            'work_order' => [
                'number' => $workOrder->wo_number,
                'status' => $workOrder->status,
                'maintenance_type' => $workOrder->maintenance_type,
                'priority' => $workOrder->priority,
                'complaint' => $workOrder->complaint,
                'created_at' => optional($workOrder->created_at)->toDateTimeString(),
                'started_at' => optional($workOrder->started_at)->toDateTimeString(),
                'completed_at' => optional($workOrder->completed_at)->toDateTimeString(),
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
                'subtotal' => (string) $purchaseOrder->subtotal,
                'tax_total' => (string) $purchaseOrder->tax_total,
                'freight_cost' => (string) $purchaseOrder->freight_cost,
                'total' => (string) $purchaseOrder->total,
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
                'quantity_ordered' => (string) $item->quantity_ordered,
                'unit_price' => (string) $item->unit_price,
                'discount_percent' => (string) $item->discount_percent,
                'tax_percent' => (string) $item->tax_percent,
                'line_total' => (string) $item->line_total,
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
                'cost' => (string) $service->cost,
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
                'subtotal' => (string) $invoice->subtotal,
                'tax_total' => (string) $invoice->tax_total,
                'discount_total' => (string) $invoice->discount_total,
                'total_amount' => (string) $invoice->total_amount,
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
                'quantity' => (string) ($item['quantity'] ?? ''),
                'unit_price' => (string) ($item['unit_price'] ?? ''),
                'line_total' => (string) ($item['line_total'] ?? ''),
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
