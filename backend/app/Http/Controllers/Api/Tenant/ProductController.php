<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\MasterData\Services\ComponentClassificationService;
use App\Domain\MasterData\Services\VehicleMasterResolver;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use App\Domain\ProductMaster\Services\ProductCompatibilityService;
use App\Domain\ProductMaster\Services\ProductConsumableSdsService;
use App\Domain\ProductMaster\Services\ProductCreationService;
use App\Domain\ProductMaster\Services\ProductImageService;
use App\Domain\ProductMaster\Services\ProductSpecificationService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreProductRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    /** Mechanical classification, trimmed to what list/detail display needs; resolves soft-deleted rows. */
    private const CLASSIFICATION_RELATIONS = [
        'componentGroup:id,code,name,abbreviation,status,deleted_at',
        'componentCategory:id,component_group_id,code,name,status,deleted_at',
        'componentSubcategory:id,component_category_id,code,name,status,deleted_at',
    ];

    public function __construct(
        private readonly ProductCompatibilityService $compatibility,
        private readonly ProductSpecificationService $specs,
        private readonly ProductConsumableSdsService $sds,
        private readonly ProductImageService $images,
        private readonly ComponentClassificationService $classification,
        private readonly ProductCreationService $creation,
        private readonly VehicleMasterResolver $vehicles,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'uom', 'compatibilities', ...self::CLASSIFICATION_RELATIONS]);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        foreach (['product_type', 'status', 'product_category_id', 'component_group_id', 'component_category_id', 'component_subcategory_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        $request->validate(['category_id' => ['nullable', 'uuid'], 'vehicle_model_id' => ['nullable', 'uuid']]);
        // Product Category filter including its direct subcategories (New RFQ product picker).
        if ($categoryId = $request->string('category_id')->value()) {
            $query->where(fn ($q) => $q->where('product_category_id', $categoryId)
                ->orWhereIn('product_category_id', ProductCategory::query()->where('parent_id', $categoryId)->select('id')));
        }
        // Vehicle Model filter through the compatibility masters: rules for that model, plus
        // brand-wide rules ("any model") of the model's brand.
        if ($modelId = $request->string('vehicle_model_id')->value()) {
            $model = VehicleModel::query()->find($modelId);
            $query->whereHas('compatibilities', fn ($c) => $c->where(fn ($w) => $w->where('vehicle_model_id', $modelId)
                ->when($model, fn ($brandWide) => $brandWide->orWhere(fn ($any) => $any->where('vehicle_brand_id', $model->vehicle_brand_id)->whereNull('vehicle_model_id')))));
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreProductRequest $request)
    {
        $product = $this->creation->create($this->context->tenantId(), Arr::except($request->validated(), ['creation_context']), (array) $request->input('spec', []));

        return $this->ok($product, 201);
    }

    public function show(Product $product)
    {
        $this->authorizeVisible($product);

        return $this->ok($product->load(self::detailRelations($product)));
    }

    /** Relations of the Product Detail payload (also served by Tire Detail → Details). */
    public static function detailRelations(Product $product): array
    {
        $relations = ['category', 'uom', 'defaultStorageBin', 'componentGroups', 'compatibilities.componentGroup', 'compatibilities.vehicleCategory', 'compatibilities.brandMaster:id,name,status,deleted_at', 'compatibilities.modelMaster:id,vehicle_brand_id,name,status,deleted_at', ...self::CLASSIFICATION_RELATIONS];
        if ($relation = self::SPEC_RELATIONS[$product->product_type] ?? null) {
            $relations[] = $relation;
        }

        return $relations;
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
            // Mechanical classification: only validated when actually changed, so an
            // untouched classification that has since been retired never blocks a save.
            'component_group_id' => ['sometimes', 'nullable', 'uuid'],
            'component_category_id' => ['sometimes', 'nullable', 'uuid'],
            'component_subcategory_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $validated = array_merge($validated, $this->classification->resolveProductClassification($product->product_type, $validated, $product));

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

        // New selections must be live (visible, ACTIVE, not deleted) groups; a
        // group already attached stays re-submittable even if since retired.
        $attachedIds = $product->componentGroups()->pluck('component_groups.id')->all();
        $request->validate(['component_group_ids' => ['array'], 'component_group_ids.*' => ['uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule($attachedIds)]]);
        $product->componentGroups()->sync($request->input('component_group_ids', []));

        return $this->ok($product->fresh('componentGroups'));
    }

    public function addCompatibility(Request $request, Product $product)
    {
        $this->authorizeOwned($product);

        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule()],
            'vehicle_category_id' => ['nullable', 'uuid', 'exists:vehicle_categories,id'],
            // Brand / Model come from the Vehicle Brand / Vehicle Model masters; empty = any.
            'vehicle_brand_id' => ['nullable', 'uuid'],
            'vehicle_model_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
        ]);
        $validated = array_merge($validated, $this->vehicles->resolve(
            $validated['vehicle_brand_id'] ?? null, $validated['vehicle_model_id'] ?? null, $this->context->tenantId(),
        ));

        $rule = ProductCompatibility::query()->create($validated + [
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
        ]);

        return $this->ok($rule, 201);
    }

    /** Edit an existing rule; a Brand/Model it already holds stays accepted even if since retired. */
    public function updateCompatibility(Request $request, Product $product, ProductCompatibility $compatibility)
    {
        $this->authorizeOwned($product);
        abort_unless($compatibility->product_id === $product->id, 404);

        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule()],
            'vehicle_category_id' => ['nullable', 'uuid', 'exists:vehicle_categories,id'],
            'vehicle_brand_id' => ['nullable', 'uuid'],
            'vehicle_model_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
        ]);
        $validated = array_merge($validated, $this->vehicles->resolve(
            $validated['vehicle_brand_id'] ?? null, $validated['vehicle_model_id'] ?? null, $this->context->tenantId(),
            current: $compatibility->only(['vehicle_brand_id', 'vehicle_model_id']),
        ));

        $compatibility->update($validated);

        return $this->ok($compatibility->fresh(['brandMaster:id,name,status,deleted_at', 'modelMaster:id,vehicle_brand_id,name,status,deleted_at']));
    }

    public function destroyCompatibility(Product $product, ProductCompatibility $compatibility)
    {
        $this->authorizeOwned($product);
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

    /** Writes to a Product's compatibility rules: the tenant's own Products only (never a platform/system one). */
    private function authorizeOwned(Product $product): void
    {
        $this->authorizeVisible($product);
        abort_if($product->tenant_id === null || $product->is_system, 403, 'System products cannot be modified by a tenant.');
    }

    private function authorizeVisible(Product $product): void
    {
        abort_unless($product->tenant_id === null || $product->tenant_id === $this->context->tenantId(), 404);
    }
}
