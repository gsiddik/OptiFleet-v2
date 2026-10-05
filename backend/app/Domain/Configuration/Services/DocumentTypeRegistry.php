<?php

namespace App\Domain\Configuration\Services;

/**
 * The one list of document types the system generates, shared by Configuration → Document
 * Numbering and Document Template (both dropdowns read it through the metadata API, so no
 * page keeps its own array). A type supports numbering when a service asks
 * DocumentNumberingService for its number, and a template when TemplateVariableRegistry
 * defines the variables its printed document exposes.
 */
class DocumentTypeRegistry
{
    /** key => [label, numbering supported] — template support comes from TemplateVariableRegistry. */
    private const TYPES = [
        'work_order' => ['Work Order', true],
        'maintenance_request' => ['Maintenance Request', true],
        'maintenance_report' => ['Maintenance Report', false],
        'inspection_report' => ['Inspection Report', false],
        'maintenance_memo' => ['Maintenance Memo (External Workshop)', true],
        'work_authorization_letter' => ['Work Authorization Letter', false],
        'workshop_invoice' => ['Recorded Workshop Invoice', false],
        'vehicle_transfer' => ['Vehicle Transfer', true],
        'stock_transfer' => ['Stock Transfer', true],
        'part_return' => ['Part Return', true],
        'purchase_request' => ['Purchase Request', true],
        'rfq' => ['Request for Quotation (RFQ)', true],
        'purchase_order' => ['Purchase Order', true],
        'goods_receipt' => ['Goods Receipt', true],
        'purchase_return' => ['Return Order (Return to Vendor)', true],
        'warranty_claim' => ['Warranty Claim', true],
        'invoice' => ['Invoice', false],
        'product_item' => ['Product Item Code', true],
        'product_sku' => ['Product SKU', true],
    ];

    /** Tokens only some document types can fill (supplied by their caller). */
    private const EXTRA_NUMBERING_TOKENS = ['product_sku' => ['ITEMTYPE', 'CG']];

    public function __construct(private readonly TemplateVariableRegistry $templates = new TemplateVariableRegistry) {}

    /** @return list<array{key: string, label: string, numbering: bool, template: bool, extra_tokens: list<string>}> */
    public function all(): array
    {
        $templateTypes = $this->templates->documentTypes();

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::TYPES[$key][0],
            'numbering' => self::TYPES[$key][1],
            'template' => in_array($key, $templateTypes, true),
            'extra_tokens' => self::EXTRA_NUMBERING_TOKENS[$key] ?? [],
        ], array_keys(self::TYPES));
    }

    /** @return list<array{key: string, label: string, numbering: bool, template: bool, extra_tokens: list<string>}> */
    public function forNumbering(): array
    {
        return array_values(array_filter($this->all(), fn ($t) => $t['numbering']));
    }

    /** @return list<array{key: string, label: string, numbering: bool, template: bool, extra_tokens: list<string>}> */
    public function forTemplates(): array
    {
        return array_values(array_filter($this->all(), fn ($t) => $t['template']));
    }

    public function supportsNumbering(string $key): bool
    {
        return (self::TYPES[$key][1] ?? false) === true;
    }

    public function supportsTemplate(string $key): bool
    {
        return in_array($key, $this->templates->documentTypes(), true);
    }

    public function label(string $key): string
    {
        return self::TYPES[$key][0] ?? ucwords(str_replace('_', ' ', $key));
    }

    public function extraTokens(string $key): array
    {
        return self::EXTRA_NUMBERING_TOKENS[$key] ?? [];
    }
}
