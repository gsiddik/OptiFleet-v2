<?php

namespace App\Domain\Configuration\Services;

/**
 * Section 9: the sole source of truth for which variables a tenant
 * template may reference, per document type. TemplateValidator rejects any
 * path a template uses that is not listed here, and this list only ever
 * describes plain-array lookups DocumentTemplateContextBuilder produces —
 * never a method call, relation, or raw model attribute — so a variable
 * being "allowed" can never grant more than "this key is present in the
 * context array built for this document type."
 */
class TemplateVariableRegistry
{
    private const COMMON_SCALARS = [
        'company.name', 'company.legal_name', 'company.address', 'company.phone', 'company.email',
        'tenant.name', 'tenant.code',
        'document_number', 'template_version', 'configuration_version', 'generated_at',
    ];

    private const DEFINITIONS = [
        'work_order' => [
            'scalars' => [
                'work_order.number', 'work_order.status', 'work_order.maintenance_type',
                'work_order.priority', 'work_order.complaint', 'work_order.created_at',
                'work_order.started_at', 'work_order.completed_at',
                'vehicle.registration_number', 'vehicle.brand', 'vehicle.model', 'vehicle.vin',
                'branch.name', 'workshop.name',
            ],
            'sections' => [
                'jobs' => ['description', 'status', 'estimated_hours', 'actual_hours'],
            ],
        ],
        'maintenance_report' => [
            'scalars' => [
                'maintenance_request.number', 'maintenance_request.priority', 'maintenance_request.complaint',
                'maintenance_request.status', 'maintenance_request.created_at',
                'vehicle.registration_number', 'vehicle.brand', 'vehicle.model',
                'branch.name', 'workshop.name',
            ],
            'sections' => [
                'jobs' => ['description', 'status', 'actual_hours'],
            ],
        ],
        'inspection_report' => [
            'scalars' => [
                'inspection.template_name', 'inspection.status', 'inspection.inspected_at',
                'vehicle.registration_number', 'vehicle.brand', 'vehicle.model',
            ],
            'sections' => [
                'findings' => ['item_name', 'result', 'note'],
            ],
        ],
        'vehicle_transfer' => [
            'scalars' => [
                'transfer.number', 'transfer.status', 'transfer.reason', 'transfer.requested_at', 'transfer.completed_at',
                'vehicle.registration_number', 'vehicle.brand', 'vehicle.model',
                'from_branch.name', 'to_branch.name',
            ],
            'sections' => [],
        ],
        'stock_transfer' => [
            'scalars' => [
                'transfer.number', 'transfer.status', 'transfer.dispatched_at', 'transfer.received_at',
                'from_warehouse.name', 'to_warehouse.name',
            ],
            'sections' => [
                'items' => ['product_name', 'quantity_sent', 'quantity_received'],
            ],
        ],
        'purchase_request' => [
            'scalars' => [
                'purchase_request.number', 'purchase_request.status', 'purchase_request.priority',
                'purchase_request.required_date', 'warehouse.name',
            ],
            'sections' => [
                'items' => ['product_name', 'requested_quantity', 'estimated_unit_price'],
            ],
        ],
        'maintenance_memo' => [
            'scalars' => [
                'maintenance_memo.reference_number', 'maintenance_memo.description', 'maintenance_memo.condition_notes',
                'maintenance_memo.priority', 'maintenance_memo.status', 'maintenance_memo.cost',
                'maintenance_memo.requested_at', 'maintenance_memo.completed_at',
                'partner.name', 'partner.address', 'partner.contact_name', 'partner.contact_phone',
                'work_order.number', 'work_order.maintenance_type',
                'vehicle.registration_number', 'vehicle.brand', 'vehicle.model',
            ],
            'sections' => [],
        ],
        'purchase_order' => [
            'scalars' => [
                'purchase_order.number', 'purchase_order.status', 'purchase_order.order_date',
                'purchase_order.expected_delivery_date', 'purchase_order.subtotal', 'purchase_order.tax_total',
                'purchase_order.freight_cost', 'purchase_order.total',
                'partner.name', 'partner.address', 'partner.contact_name', 'partner.contact_phone',
                'delivery_warehouse.name',
            ],
            'sections' => [
                'items' => ['product_name', 'quantity_ordered', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'],
            ],
        ],
        'goods_receipt' => [
            'scalars' => [
                'goods_receipt.number', 'goods_receipt.status', 'goods_receipt.received_at',
                'warehouse.name', 'partner.name',
            ],
            'sections' => [
                'items' => ['product_name', 'quantity_accepted', 'quantity_rejected', 'quantity_damaged'],
            ],
        ],
        'warranty_claim' => [
            'scalars' => [
                'claim.number', 'claim.status', 'claim.reason', 'claim.claim_amount',
                'claim.failure_date', 'claim.settled_at',
                'vehicle.registration_number', 'partner.name',
            ],
            'sections' => [],
        ],
        'invoice' => [
            'scalars' => [
                'invoice.number', 'invoice.status', 'invoice.issue_date', 'invoice.due_date',
                'invoice.subtotal', 'invoice.tax_total', 'invoice.total',
            ],
            'sections' => [
                'items' => ['description', 'quantity', 'unit_price', 'line_total'],
            ],
        ],
    ];

    public function documentTypes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function forDocumentType(string $documentType): array
    {
        if (! array_key_exists($documentType, self::DEFINITIONS)) {
            throw new TemplateValidationException("Unknown template document type '{$documentType}'.");
        }

        $definition = self::DEFINITIONS[$documentType];

        return [
            'scalars' => [...self::COMMON_SCALARS, ...$definition['scalars']],
            'sections' => $definition['sections'],
        ];
    }

    /**
     * Section 11: deterministic, non-mutating sample data for previewing a
     * template with no live document present.
     */
    public function sampleContext(string $documentType): array
    {
        $definition = $this->forDocumentType($documentType);

        $context = [
            'company' => ['name' => 'OptiFleet Demo Corp', 'legal_name' => 'OptiFleet Demo Corp Ltd', 'address' => '123 Fleet Avenue', 'phone' => '+62-21-5550100', 'email' => 'ops@example.com'],
            'tenant' => ['name' => 'Sample Tenant', 'code' => 'SAMPLE'],
            'document_number' => 'SAMPLE/2026/000001',
            'template_version' => 1,
            'configuration_version' => 1,
            'generated_at' => now()->toDateTimeString(),
        ];

        foreach ($definition['scalars'] as $path) {
            if (str_contains($path, '.')) {
                [$group, $field] = explode('.', $path, 2);
                $context[$group][$field] ??= ucwords(str_replace('_', ' ', $field)).' (sample)';
            }
        }

        foreach ($definition['sections'] as $name => $fields) {
            $context[$name] = [
                array_combine($fields, array_map(fn ($f) => ucwords(str_replace('_', ' ', $f)).' 1', $fields)),
                array_combine($fields, array_map(fn ($f) => ucwords(str_replace('_', ' ', $f)).' 2', $fields)),
            ];
        }

        return $context;
    }
}
