<?php

namespace App\Domain\ProductMaster\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\MasterData\Services\ComponentClassificationService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Http\Requests\Tenant\StoreProductRequest;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single Product creation path. POST /app/products and every seeder that creates a
 * Product go through here, so a seeded Product is structurally identical to one a user
 * creates: same Item Type ↔ category check, Component Group → Category → Subcategory rules
 * (Category mandatory for Sparepart/Consumable/Tire/Rim), dynamic specification validation,
 * server-generated Item Code and SKU.
 */
class ProductCreationService
{
    public const SPEC_RELATIONS = [
        'SPARE_PART' => 'sparepartSpec',
        'CONSUMABLE' => 'consumableSpec.storageRequirements',
        'RIM' => 'rimSpec',
        'TIRE' => 'tireSpec',
        'TOOL' => 'toolSpec.toolType',
        'EQUIPMENT' => 'equipmentSpec.equipmentType',
    ];

    public function __construct(
        private readonly DocumentNumberingService $numbers,
        private readonly ProductSpecificationService $specs,
        private readonly ComponentClassificationService $classification,
        private readonly ProductSkuService $skus,
        private readonly TenantContext $context,
    ) {}

    /**
     * For callers without an HTTP request (seeders, imports): validates the general fields
     * with exactly the StoreProductRequest rules first. The tenant context must already be
     * set to $tenantId, since those rules scope category/UOM/bin lookups to it.
     *
     * @param  array<string, mixed>  $input  StoreProductRequest fields plus an optional `spec` array
     */
    public function createFromInput(string $tenantId, array $input): Product
    {
        if ($this->context->tenantId() !== $tenantId) {
            throw new \LogicException('The tenant context must be set to the tenant the Product is created for.');
        }

        $validated = Validator::make($input, (new StoreProductRequest)->rules())->validate();

        return $this->create($tenantId, $validated, (array) ($input['spec'] ?? []));
    }

    /**
     * Every Create Product check (StoreProductRequest rules, Item Type ↔ category, classification, dynamic
     * specification) without any write and without consuming a number — the Excel import preview.
     *
     * @param  array<string, mixed>  $input  StoreProductRequest fields plus an optional `spec` array
     *
     * @throws ValidationException
     */
    public function check(string $tenantId, array $input): void
    {
        if ($this->context->tenantId() !== $tenantId) {
            throw new \LogicException('The tenant context must be set to the tenant the Product is checked for.');
        }
        $validated = Validator::make($input, (new StoreProductRequest)->rules())->validate();
        $this->prepare($validated, (array) ($input['spec'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $validated  general fields that already passed StoreProductRequest
     * @param  array<string, mixed>  $spec  dynamic specification input for the Item Type
     */
    public function create(string $tenantId, array $validated, array $spec): Product
    {
        $productType = $validated['product_type'];
        [$validated, $generalOverrides, $validatedSpec] = $this->prepare($validated, $spec);

        $product = DB::transaction(function () use ($tenantId, $validated, $generalOverrides, $validatedSpec) {
            $number = $this->numbers->generate('product_item', $tenantId);

            // Issued exactly once, here; later classification/master-data changes never touch it.
            $validated['sku'] = $this->skus->generate($tenantId, $validated['product_type'], $validated['component_group_id']);

            $product = Product::query()->create(array_merge($validated, $generalOverrides) + [
                'tenant_id' => $tenantId,
                'code' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'is_system' => false,
                'status' => 'ACTIVE',
            ]);

            $this->specs->persist($product, $validatedSpec);

            return $product;
        });

        $relation = self::SPEC_RELATIONS[$productType] ?? null;

        return $relation ? $product->load($relation) : $product;
    }

    /**
     * Item Type ↔ category, Component Group → Category → Subcategory (hierarchy, availability, Item Type
     * applicability; Category mandatory for Sparepart/Consumable/Tire/Rim) and the dynamic specification —
     * validated BEFORE the numbering sequence is touched, so an invalid submission never burns an Item Code.
     *
     * @return array{0: array, 1: array, 2: array} [validated with classification, general overrides, validated spec]
     */
    private function prepare(array $validated, array $spec): array
    {
        $productType = $validated['product_type'];
        if (! empty($validated['product_category_id'])) {
            $category = ProductCategory::query()->find($validated['product_category_id']);
            if ($category && $category->item_type && $category->item_type !== $productType) {
                throw ValidationException::withMessages(['product_category_id' => 'The selected category does not apply to this Item Type.']);
            }
        }

        $validated = array_merge($validated, $this->classification->resolveProductClassification($productType, $validated));
        $general = array_intersect_key($validated, array_flip(['brand', 'track_serial_number', 'track_batch', 'product_category_id']));
        ['general' => $generalOverrides, 'spec' => $validatedSpec] = $this->specs->validate($productType, $general, $spec);

        return [$validated, $generalOverrides, $validatedSpec];
    }
}
