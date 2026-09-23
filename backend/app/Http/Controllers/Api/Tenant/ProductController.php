<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use App\Domain\ProductMaster\Services\ProductCompatibilityService;
use App\Domain\ProductMaster\Services\ProductConsumableSdsService;
use App\Domain\ProductMaster\Services\ProductImageService;
use App\Domain\ProductMaster\Services\ProductSpecificationService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreProductRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const SPEC_RELATIONS = [
        'SPARE_PART' => 'sparepartSpec',
        'CONSUMABLE' => 'consumableSpec.storageRequirements',
        'RIM' => 'rimSpec',
        'TIRE' => 'tireSpec',
        'TOOL' => 'toolSpec.toolType',
        'EQUIPMENT' => 'equipmentSpec.equipmentType',
    ];

    public function __construct(
        private readonly ProductCompatibilityService $compatibility,
        private readonly DocumentNumberingService $numbers,
        private readonly ProductSpecificationService $specs,
        private readonly ProductConsumableSdsService $sds,
        private readonly ProductImageService $images,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'uom', 'compatibilities']);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        foreach (['product_type', 'status', 'product_category_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreProductRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validated();
        $productType = $validated['product_type'];

        if (! empty($validated['product_category_id'])) {
            $category = ProductCategory::query()->find($validated['product_category_id']);
            if ($category && $category->item_type && $category->item_type !== $productType) {
                throw ValidationException::withMessages(['product_category_id' => 'The selected category does not apply to this Item Type.']);
            }
        }

        // Validated BEFORE the numbering sequence is touched, so an invalid
        // spec submission never burns an Item Code.
        ['general' => $generalOverrides, 'spec' => $validatedSpec] = $this->specs->validate(
            $productType,
            $request->only(['brand', 'track_serial_number', 'track_batch', 'product_category_id']),
            (array) $request->input('spec', [])
        );

        $product = DB::transaction(function () use ($tenantId, $validated, $generalOverrides, $validatedSpec) {
            $number = $this->numbers->generate('product_item', $tenantId);

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

        return $this->ok($relation ? $product->load($relation) : $product, 201);
    }

    public function show(Product $product)
    {
        $this->authorizeVisible($product);

        $relations = ['category', 'uom', 'defaultStorageBin', 'componentGroups', 'compatibilities.componentGroup', 'compatibilities.vehicleCategory'];
        if ($relation = self::SPEC_RELATIONS[$product->product_type] ?? null) {
            $relations[] = $relation;
        }

        return $this->ok($product->load($relations));
    }

    /**
     * Section 15/Edit Dynamic Form: Edit must reconstruct and persist the Item Type's spec
     * table, not just the generic physical columns — previously this endpoint never touched
     * Category/Subcategory/UOM/Default Storage Location or any of the 6 spec tables at all.
     * Item Type itself stays immutable on Edit (same precedent as Item Code): changing it
     * would mean an entirely different spec table, which is a new-product decision, not an
     * edit. A `spec` payload is required only when the product_type actually has spec fields
     * (not OTHER); its absence is a no-op rather than an error, so a caller only touching
     * general fields (e.g. a bare Active toggle) doesn't have to resend the whole form.
     */
    public function update(Request $request, Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'product_category_id' => ['sometimes', 'uuid', \Illuminate\Validation\Rule::exists('product_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'uom_id' => ['sometimes', 'uuid', \Illuminate\Validation\Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:100'],
            'production_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_mm' => ['nullable', 'numeric', 'min:0'],
            'width_mm' => ['nullable', 'numeric', 'min:0'],
            'height_mm' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:255'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            // Mandatory per the authoritative document, but a pre-existing
            // Product may legitimately still have NULL here (no destructive
            // database-wide backfill was performed to satisfy the new rule).
            // Strategy: once the caller explicitly touches this field (i.e. the
            // key is present in the request — the edit flow that carries the
            // hierarchical Warehouse->Zone->Rack->Bin picker), it must resolve
            // to a real bin and can never be cleared back to NULL. An update
            // that doesn't touch this field at all (e.g. a status toggle) is
            // left alone, so a legacy NULL record remains readable and editable.
            'default_storage_bin_id' => ['sometimes', 'required', 'uuid', \Illuminate\Validation\Rule::exists('warehouse_bins', 'id')->where('tenant_id', $tenantId)],
            'track_serial_number' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'reference_tread_depth_mm' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
        ]);

        if (! empty($validated['product_category_id'])) {
            $category = ProductCategory::query()->find($validated['product_category_id']);
            if ($category && $category->item_type && $category->item_type !== $product->product_type) {
                throw ValidationException::withMessages(['product_category_id' => 'The selected category does not apply to this Item Type.']);
            }
        }

        $generalOverrides = [];
        $validatedSpec = null;
        if ($request->has('spec') && (self::SPEC_RELATIONS[$product->product_type] ?? null)) {
            // A spec-only Edit (e.g. only Specification/Grade changed, Category untouched)
            // must still evaluate the Conditional-Mandatory rule against the product's
            // EXISTING category, not silently treat it as "no category" (i.e. never required).
            $specValidationInput = $validated + ['product_category_id' => $product->product_category_id];
            ['general' => $generalOverrides, 'spec' => $validatedSpec] = $this->specs->validate(
                $product->product_type,
                $specValidationInput,
                (array) $request->input('spec', []),
                includeCompatibilities: false,
            );
        }

        DB::transaction(function () use ($product, $validated, $generalOverrides, $validatedSpec) {
            $product->update(array_merge($validated, $generalOverrides));
            if ($validatedSpec !== null) {
                $this->specs->persist($product, $validatedSpec, includeCompatibilities: false);
            }
        });

        $relation = self::SPEC_RELATIONS[$product->product_type] ?? null;
        $product = $product->fresh();

        return $this->ok($relation ? $product->load($relation) : $product);
    }

    public function syncComponentGroups(Request $request, Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $request->validate(['component_group_ids' => ['array'], 'component_group_ids.*' => ['uuid']]);
        $product->componentGroups()->sync($request->input('component_group_ids', []));

        return $this->ok($product->fresh('componentGroups'));
    }

    public function addCompatibility(Request $request, Product $product)
    {
        $this->authorizeVisible($product);

        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'vehicle_category_id' => ['nullable', 'uuid', 'exists:vehicle_categories,id'],
            'vehicle_brand' => ['nullable', 'string', 'max:100'],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $rule = ProductCompatibility::query()->create($validated + [
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
        ]);

        return $this->ok($rule, 201);
    }

    public function destroyCompatibility(Product $product, ProductCompatibility $compatibility)
    {
        $this->authorizeVisible($product);
        abort_unless($compatibility->product_id === $product->id, 404);

        $compatibility->delete();

        return $this->message('Compatibility rule removed.');
    }

    public function uploadImage(Request $request, Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);

        $product = $this->images->upload($product, $request->file('file'));

        return $this->ok($product);
    }

    public function showImage(Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->image_path === null, 404);

        return Storage::disk('local')->response($product->image_path, $product->image_original_filename);
    }

    public function destroyImage(Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $this->images->delete($product);

        return $this->message('Product image removed.');
    }

    public function uploadSds(Request $request, Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf']]);

        $spec = $this->sds->upload($product, $request->file('file'));

        return $this->ok($spec);
    }

    public function showSds(Product $product)
    {
        $this->authorizeVisible($product);
        $spec = $product->consumableSpec;
        abort_if($spec === null || $spec->sds_file_path === null, 404);

        return Storage::disk('local')->response($spec->sds_file_path, $spec->sds_original_filename);
    }

    public function destroySds(Product $product)
    {
        $this->authorizeVisible($product);
        abort_if($product->is_system, 403, 'System master data cannot be modified by a tenant.');

        $this->sds->delete($product);

        return $this->message('Safety Data Sheet removed.');
    }

    public function compatibleFor(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'component_group_id' => ['nullable', 'uuid'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);

        return $this->ok($this->compatibility->compatibleProducts($vehicle, $request->input('component_group_id')));
    }

    private function authorizeVisible(Product $product): void
    {
        abort_unless($product->tenant_id === null || $product->tenant_id === $this->context->tenantId(), 404);
    }
}
