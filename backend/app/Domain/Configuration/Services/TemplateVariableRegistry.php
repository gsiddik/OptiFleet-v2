<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Shared\Support\Messages;

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
                'work_order.started_at', 'work_order.completed_at', 'work_order.revision',
                'vehicle.registration_number', 'vehicle.brand', 'vehicle.model', 'vehicle.vin',
                'branch.name', 'workshop.name',
                // Consolidated External Workshop business rules: a plain boolean conditional
                // (not a repeating list) is registered as a scalar, per TemplateValidator's
                // isConditionalScalar check — {{#is_external}}/{{^is_external}} branch the
                // template between the internal Jobs table and the External Findings section.
                'is_external',
            ],
            'sections' => [
                'jobs' => ['description', 'status', 'estimated_hours', 'actual_hours'],
                'findings' => ['severity', 'description', 'status'],
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
        // "Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice" Section 6:
        // rendered only from the WAL's own frozen snapshot columns (see
        // DocumentTemplateContextBuilder::forWorkAuthorizationLetter) — never the live
        // Partner/Vehicle/Tenant it was generated against.
        'work_authorization_letter' => [
            'scalars' => [
                'wal.number', 'wal.issue_date', 'wal.workshop_name', 'wal.workshop_address',
                'wal.workshop_pic', 'wal.workshop_phone', 'wal.vehicle_unit_number',
                'wal.vehicle_registration_number', 'wal.vehicle_make_model', 'wal.vehicle_odometer',
                'wal.company_name', 'wal.revision', 'work_order.number',
            ],
            'sections' => [],
        ],
        // R1: OptiFleet's own record of an externally-issued Workshop Invoice —
        // deliberately named/worded so a template can never be built that implies
        // OptiFleet issued the invoice (see ConfigurationDefaultsSeeder's default template).
        'workshop_invoice' => [
            'scalars' => [
                'workshop_invoice.external_invoice_number', 'workshop_invoice.invoice_date', 'workshop_invoice.due_date',
                'workshop_invoice.currency', 'workshop_invoice.subtotal', 'workshop_invoice.tax_total',
                'workshop_invoice.discount_total', 'workshop_invoice.total_amount', 'workshop_invoice.status',
                'workshop_invoice.notes', 'workshop_invoice.reconciliation_note',
                'partner.name', 'partner.address', 'partner.contact_name', 'partner.contact_phone',
                'work_order.number', 'maintenance_memo.reference_number', 'maintenance_memo.description',
            ],
            'sections' => [
                'items' => ['description', 'quantity', 'unit_price', 'line_total'],
            ],
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
        'purchase_return' => [
            'scalars' => [
                'purchase_return.number', 'purchase_return.returned_date', 'purchase_return.return_option', 'purchase_return.status',
                'purchase_return.notes', 'purchase_order.number',
                'partner.name', 'partner.address', 'partner.contact_name', 'partner.contact_phone', 'warehouse.name',
            ],
            'sections' => [
                'items' => ['product_name', 'product_code', 'quantity'],
            ],
        ],
        'rfq' => [
            'scalars' => [
                'rfq.number', 'rfq.issue_date', 'rfq.response_deadline', 'rfq.status',
                'vendor.name', 'vendor.address', 'vendor.contact_name', 'vendor.contact_phone', 'vendor.contact_email',
                'warehouse.name', 'warehouse.address', 'printed_by.name', 'printed_at',
            ],
            'sections' => [
                'items' => ['line_no', 'product_code', 'product_name', 'quantity', 'uom'],
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
        // i18n: every status also has its display label in the document's language (`….status_label`).
        $withLabels = fn (array $paths) => array_values(array_unique([
            ...$paths,
            ...array_map(fn (string $p) => $p.'_label', array_filter($paths, fn (string $p) => $p === 'status' || str_ends_with($p, '.status'))),
        ]));

        return [
            'scalars' => $withLabels([...self::COMMON_SCALARS, ...$definition['scalars']]),
            'sections' => array_map($withLabels, $definition['sections']),
        ];
    }

    /** Friendly names of repeating blocks (sections) shown in the visual template editor. */
    private const SECTION_LABELS = ['jobs' => 'Jobs', 'findings' => 'Findings', 'items' => 'Items'];

    /** Friendly names of variable groups. */
    private const GROUP_LABELS = [
        'company' => 'Company', 'tenant' => 'Tenant', 'wal' => 'Work Authorization Letter', 'claim' => 'Warranty Claim',
        'from_branch' => 'From Branch', 'to_branch' => 'To Branch', 'from_warehouse' => 'From Warehouse', 'to_warehouse' => 'To Warehouse',
        'delivery_warehouse' => 'Delivery Warehouse', 'partner' => 'Vendor / Partner', 'vendor' => 'Vendor', 'printed_by' => 'Printed By',
        'rfq' => 'RFQ',
    ];

    /**
     * The variables of a document type described for non-technical users (visual template
     * editor cards): label, key, category, type and a short description — scalars, then each
     * repeating block with the fields valid inside it.
     *
     * @return array{variables: list<array{key: string, label: string, category: string, type: string, description: string}>, blocks: list<array{name: string, label: string, description: string, fields: list<array{key: string, label: string, category: string, type: string, description: string}>}>}
     */
    public function catalog(string $documentType): array
    {
        $definition = $this->forDocumentType($documentType);
        $describe = function (string $path, ?string $block = null): array {
            [$group, $field] = str_contains($path, '.') ? explode('.', $path, 2) : [null, $path];
            $category = $block !== null ? (self::SECTION_LABELS[$block] ?? ucwords(str_replace('_', ' ', $block)))
                : ($group !== null ? (self::GROUP_LABELS[$group] ?? ucwords(str_replace('_', ' ', $group))) : 'Document');
            $label = match ($path) {
                'document_number' => 'Document Number',
                'generated_at' => 'Generated At',
                'template_version' => 'Template Version',
                'configuration_version' => 'Configuration Version',
                'is_external' => 'External Workshop (yes / no)',
                default => ucwords(str_replace(['_', '.'], ' ', $field)),
            };
            $type = match (true) {
                str_starts_with($field, 'is_') => 'Yes / No',
                (bool) preg_match('/(_at|_date|^date|deadline)$/', $field) => 'Date',
                (bool) preg_match('/(quantity|total|price|hours|cost|amount|subtotal|percent|odometer|revision|version|line_no)/', $field) => 'Number',
                default => 'Text',
            };

            return ['key' => $path, 'label' => $label, 'category' => $category, 'type' => $type,
                'description' => $block !== null ? "{$label} of each {$category} row." : "{$category} — {$label}."];
        };

        return [
            'variables' => array_map(fn ($path) => $describe($path), $definition['scalars']),
            'blocks' => array_map(fn ($name, $fields) => [
                'name' => $name,
                'label' => self::SECTION_LABELS[$name] ?? ucwords(str_replace('_', ' ', $name)),
                'description' => Messages::text('configuration.help.sectionRepeats', ['section' => strtolower(self::SECTION_LABELS[$name] ?? $name)]),
                'fields' => array_map(fn ($field) => $describe($field, $name), $fields),
            ], array_keys($definition['sections']), array_values($definition['sections'])),
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
