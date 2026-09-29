<?php

namespace App\Domain\ProductMaster\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Server-side Product SKU (owner-approved): [Item Type code]-[Component Group
 * abbreviation]-[sequence], e.g. SPR-BRK-000123, generated through the
 * configurable `product_sku` numbering format — never a separate generator.
 *
 *  - The abbreviation is read from the Component Group row itself, under a
 *    share lock, so it cannot change while a SKU is being issued (an
 *    abbreviation update takes FOR UPDATE on the same row and is then
 *    rejected because the group is in use).
 *  - A Product without a Component Group (typically Tools/Equipment) gets
 *    [Item Type code]-[sequence], e.g. TOL-000001 — a two-segment SKU that
 *    can never be mistaken for a group abbreviation.
 *  - A number already taken by a legacy, manually entered SKU is skipped.
 *  - Issued once at creation; nothing ever regenerates it.
 *
 * Must run inside the caller's transaction (the sequence row lock is held
 * until commit).
 */
class ProductSkuService
{
    public const ITEM_TYPE_CODES = [
        'SPARE_PART' => 'SPR',
        'CONSUMABLE' => 'CON',
        'TIRE' => 'TIR',
        'RIM' => 'RIM',
        'TOOL' => 'TOL',
        'EQUIPMENT' => 'EQP',
        'OTHER' => 'OTH',
    ];

    private const MAX_ATTEMPTS = 100;

    public function __construct(private readonly DocumentNumberingService $numbers) {}

    public function generate(string $tenantId, string $productType, ?string $componentGroupId): string
    {
        $abbreviation = '';
        if ($componentGroupId !== null) {
            $abbreviation = DB::table('component_groups')->where('id', $componentGroupId)->sharedLock()->value('abbreviation');
            if ($abbreviation === null) {
                throw ValidationException::withMessages([
                    'component_group_id' => 'The selected Component Group has no abbreviation yet; set its 3-letter abbreviation before creating Products (it is part of the SKU).',
                ]);
            }
        }

        $context = ['ITEMTYPE' => self::ITEM_TYPE_CODES[$productType] ?? 'OTH', 'CG' => $abbreviation];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $sku = $this->numbers->generate('product_sku', $tenantId, context: $context)['document_number'];
            $taken = DB::table('products')->where('tenant_id', $tenantId)->where('sku', $sku)->exists();
            if (! $taken) {
                return $sku;
            }
        }

        throw new RuntimeException("Could not issue a free SKU for prefix {$context['ITEMTYPE']}-{$context['CG']} after ".self::MAX_ATTEMPTS.' attempts.');
    }
}
