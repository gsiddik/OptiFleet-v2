<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\WorkOrder\Models\WorkOrder;

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
