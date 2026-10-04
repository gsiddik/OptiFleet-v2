<?php

namespace App\Domain\ProductMaster\Services;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use App\Domain\ProductMaster\Models\ProductConsumableSpec;
use App\Domain\ProductMaster\Models\ProductEquipmentSpec;
use App\Domain\ProductMaster\Models\ProductSparepartSpec;
use App\Domain\ProductMaster\Models\ProductToolSpec;
use App\Domain\Tire\Models\ProductRimSpec;
use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Models\TireTraCode;
use App\Domain\Tire\Models\TireTraStarRating;
use App\Domain\MasterData\Services\VehicleMasterResolver;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Next Improvement Tenant Portal - Products" (authoritative document):
 * the per-Item-Type Dynamic Product Form specification. One method per
 * Item Type validates that type's Mandatory/Optional/Conditional fields
 * (Frontend UX is expected to mirror the same conditions, but this layer
 * is authoritative — a request bypassing the UI must be rejected the same
 * way) and persists the corresponding Class-Table-Inheritance spec row.
 * System-derived Tire values are computed here, never accepted from the
 * client, so they can never become an independently-editable second
 * source of truth.
 */
class ProductSpecificationService
{
    private const INTERVAL_UNITS = 'DAYS,WEEKS,MONTHS,YEARS';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Validates the general, per-type-conditional fields (brand,
     * track_serial_number, track_batch) and the spec payload shape for
     * the given product_type, WITHOUT touching the database. Called
     * before a numbering sequence value is consumed, so an invalid
     * submission never burns an Item Code.
     *
     * @return array{general: array, spec: array}
     */
    public function validate(string $productType, array $generalInput, array $specInput, bool $includeCompatibilities = true): array
    {
        $generalRules = match ($productType) {
            'SPARE_PART', 'RIM', 'TIRE', 'EQUIPMENT' => ['brand' => ['required', 'string', 'max:100']],
            default => ['brand' => ['nullable', 'string', 'max:100']],
        };
        if (in_array($productType, ['SPARE_PART', 'RIM', 'TOOL', 'EQUIPMENT'], true)) {
            $generalRules['track_serial_number'] = ['required', 'boolean'];
        }
        if ($productType === 'CONSUMABLE') {
            $generalRules['track_batch'] = ['required', 'boolean'];
        }
        $general = Validator::make($generalInput, $generalRules)->validate();

        $spec = match ($productType) {
            'SPARE_PART' => $this->validateSparepart($specInput, $includeCompatibilities),
            'CONSUMABLE' => $this->validateConsumable($specInput, $this->specificationGradeRequired($generalInput['product_category_id'] ?? null)),
            'RIM' => $this->validateRim($specInput),
            'TIRE' => $this->validateTire($specInput),
            'TOOL' => $this->validateTool($specInput),
            'EQUIPMENT' => $this->validateEquipment($specInput),
            default => [],
        };

        return ['general' => $general, 'spec' => $spec];
    }

    /**
     * Persists the CTI spec row (and any related rows) for an already-validated Product.
     * Each per-type persistX() uses updateOrCreate() keyed on product_id, so this same
     * method serves both Create (no row exists yet — behaves like a plain insert) and Edit
     * (Section 15: Edit must reconstruct and save the Item Type's spec table, not just the
     * generic physical columns). Vehicle Compatibility is deliberately excluded from the
     * Edit path — it already has its own dedicated add/remove endpoints/UI on the Product
     * detail page, so resubmitting the dynamic form must never touch it.
     */
    public function persist(Product $product, array $validatedSpec, bool $includeCompatibilities = true): void
    {
        match ($product->product_type) {
            'SPARE_PART' => $this->persistSparepart($product, $validatedSpec, $includeCompatibilities),
            'CONSUMABLE' => $this->persistConsumable($product, $validatedSpec),
            'RIM' => $this->persistRim($product, $validatedSpec, $includeCompatibilities),
            'TIRE' => $this->persistTire($product, $validatedSpec),
            'TOOL' => $this->persistTool($product, $validatedSpec),
            'EQUIPMENT' => $this->persistEquipment($product, $validatedSpec),
            default => null,
        };
    }

    // --- Sparepart ---

    private function validateSparepart(array $input, bool $includeCompatibilities = true): array
    {
        $tenantId = $this->context->tenantId();

        $validated = Validator::make($input, [
            'part_number' => ['required', 'string', 'max:100'],
            'part_type' => ['required', 'in:GENUINE,OEM,OES,AFTERMARKET'],
            'oem_part_number' => ['nullable', 'string', 'max:100'],
            'alternate_part_numbers' => ['nullable', 'array'],
            'alternate_part_numbers.*' => ['string', 'max:100'],
            'specification' => ['nullable', 'string'],
            'applicable_position' => ['nullable', 'array'],
            'applicable_position.*' => ['string', 'in:FRONT,REAR,LEFT,RIGHT,UPPER,LOWER,INNER,OUTER'],
            'critical_part' => ['nullable', 'boolean'],
            'warranty_period_value' => ['nullable', 'integer', 'min:0'],
            'warranty_period_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            'warranty_mileage_km' => ['nullable', 'integer', 'min:0'],
            'shelf_life_value' => ['nullable', 'integer', 'min:0'],
            'shelf_life_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            // Vehicle Compatibility is Mandatory for Sparepart — at least one row — but only
            // when this submission actually manages compatibilities (Create). On Edit, the
            // dynamic form never touches Vehicle Compatibility (it has its own dedicated
            // add/remove endpoints on the Product detail page), so this key is skipped/absent.
            'compatibilities' => [$includeCompatibilities ? 'required' : 'nullable', 'array', $includeCompatibilities ? 'min:1' : 'sometimes'],
            // Brand / Model come from the Vehicle Brand / Vehicle Model masters (by id), never free text.
            'compatibilities.*.vehicle_brand_id' => ['required', 'uuid'],
            'compatibilities.*.vehicle_model_id' => ['required', 'uuid'],
            'compatibilities.*.variant' => ['nullable', 'string', 'max:100'],
            'compatibilities.*.year_from' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'compatibilities.*.year_to' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'compatibilities.*.position' => ['nullable', 'string', 'max:50'],
            'compatibilities.*.vehicle_category_id' => ['nullable', 'uuid', Rule::exists('vehicle_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'compatibilities.*.component_group_id' => ['nullable', 'uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule()],
        ])->validate();

        return $this->resolveCompatibilityVehicles($validated, true);
    }

    private function persistSparepart(Product $product, array $v, bool $includeCompatibilities = true): void
    {
        ProductSparepartSpec::query()->updateOrCreate(['product_id' => $product->id], [
            'part_number' => $v['part_number'],
            'part_type' => $v['part_type'],
            'oem_part_number' => $v['oem_part_number'] ?? null,
            'alternate_part_numbers' => $v['alternate_part_numbers'] ?? null,
            'specification' => $v['specification'] ?? null,
            'applicable_position' => $v['applicable_position'] ?? null,
            'critical_part' => $v['critical_part'] ?? null,
            'warranty_period_value' => $v['warranty_period_value'] ?? null,
            'warranty_period_unit' => $v['warranty_period_unit'] ?? null,
            'warranty_mileage_km' => $v['warranty_mileage_km'] ?? null,
            'shelf_life_value' => $v['shelf_life_value'] ?? null,
            'shelf_life_unit' => $v['shelf_life_unit'] ?? null,
        ]);

        if ($includeCompatibilities) {
            $this->persistCompatibilities($product, $v['compatibilities']);
        }
    }

    // --- Consumable ---

    /**
     * Owner decision: Specification/Grade's Conditional-Mandatory trigger is the Product's
     * OWN Category/Subcategory (`product_category_id` already resolves to whichever
     * granularity the user picked — the leaf, Subcategory if chosen else Category — so a
     * single flag on that one row covers both levels without climbing to a parent).
     * `requires_specification_grade` is Superadmin-managed platform master data, never a
     * hardcoded name/code comparison, so a tenant can never accidentally change this rule
     * by renaming a category.
     */
    private function specificationGradeRequired(?string $productCategoryId): bool
    {
        if ($productCategoryId === null) {
            return false;
        }

        return (bool) ProductCategory::query()->whereKey($productCategoryId)->value('requires_specification_grade');
    }

    private function validateConsumable(array $input, bool $specificationGradeRequired = false): array
    {
        $v = Validator::make($input, [
            'grade_specification' => [$specificationGradeRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'package_size_value' => ['nullable', 'numeric', 'min:0'],
            'package_size_uom_id' => ['nullable', 'uuid', $this->uomExistsRule()],
            'purchase_uom_id' => ['nullable', 'uuid', $this->uomExistsRule()],
            'issue_uom_id' => ['nullable', 'uuid', $this->uomExistsRule()],
            'conversion_to_base_uom' => ['nullable', 'numeric', 'min:0'],
            'track_expiry' => ['required', 'boolean'],
            'shelf_life_value' => ['nullable', 'integer', 'min:0'],
            'shelf_life_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            'is_hazardous' => ['required', 'boolean'],
            'storage_requirement_ids' => ['nullable', 'array'],
            'storage_requirement_ids.*' => ['uuid', Rule::exists('storage_requirements', 'id')->where(fn ($q) => $q->where('tenant_id', $this->context->tenantId())->orWhereNull('tenant_id'))],
            'sds_file_path' => ['nullable', 'string'],
            'sds_original_filename' => ['nullable', 'string'],
        ])->validate();

        // Conditional Mandatory: "Wajib jika Expiry Tracking = Yes".
        if ($v['track_expiry'] && empty($v['shelf_life_value'])) {
            throw ValidationException::withMessages(['shelf_life_value' => 'Shelf Life is required when Expiry Tracking is enabled.']);
        }
        // Conditional Mandatory: "Wajib jika Hazardous = Yes".
        if ($v['is_hazardous'] && empty($v['storage_requirement_ids'])) {
            throw ValidationException::withMessages(['storage_requirement_ids' => 'Storage Requirement is required when the consumable is marked Hazardous.']);
        }

        return $v;
    }

    private function persistConsumable(Product $product, array $v): void
    {
        // Conditional Mandatory: "Wajib jika Purchase UOM != Base UOM".
        if (! empty($v['purchase_uom_id']) && $v['purchase_uom_id'] !== $product->uom_id && empty($v['conversion_to_base_uom'])) {
            throw ValidationException::withMessages(['conversion_to_base_uom' => 'Conversion to Base UOM is required when Purchase UOM differs from Base UOM.']);
        }

        $spec = ProductConsumableSpec::query()->updateOrCreate(['product_id' => $product->id], [
            'grade_specification' => $v['grade_specification'] ?? null,
            'package_size_value' => $v['package_size_value'] ?? null,
            'package_size_uom_id' => $v['package_size_uom_id'] ?? null,
            'purchase_uom_id' => $v['purchase_uom_id'] ?? null,
            'conversion_to_base_uom' => $v['conversion_to_base_uom'] ?? null,
            'issue_uom_id' => $v['issue_uom_id'] ?? null,
            'track_expiry' => $v['track_expiry'],
            'shelf_life_value' => $v['shelf_life_value'] ?? null,
            'shelf_life_unit' => $v['shelf_life_unit'] ?? null,
            'is_hazardous' => $v['is_hazardous'],
            'sds_file_path' => $v['sds_file_path'] ?? null,
            'sds_original_filename' => $v['sds_original_filename'] ?? null,
        ]);

        // Always sync (not `if (!empty(...))`) so an Edit that explicitly clears every
        // Storage Requirement actually clears them, rather than leaving stale rows attached.
        $spec->storageRequirements()->sync($v['storage_requirement_ids'] ?? []);
    }

    // --- Rim ---

    private function validateRim(array $input): array
    {
        $validated = Validator::make($input, [
            'model' => ['nullable', 'string', 'max:100'],
            'rim_type' => ['required', 'in:STEEL,ALLOY,FORGED'],
            'diameter_inch' => ['required', 'numeric', 'min:0'],
            'width_inch' => ['required', 'numeric', 'min:0'],
            'bolt_holes' => ['required', 'integer', 'min:1'],
            'pcd_mm' => ['required', 'numeric', 'min:0'],
            'center_bore_mm' => ['nullable', 'numeric', 'min:0'],
            'offset_mm' => ['nullable', 'numeric'],
            'material' => ['nullable', 'string', 'max:100'],
            'max_load_kg' => ['nullable', 'numeric', 'min:0'],
            'compatible_tire_sizes' => ['nullable', 'array'],
            'compatible_tire_sizes.*' => ['string', 'max:50'],
            // Vehicle Compatibility is Optional for Rim.
            'compatibilities' => ['nullable', 'array'],
            'compatibilities.*.vehicle_brand_id' => ['required_with:compatibilities', 'uuid'],
            'compatibilities.*.vehicle_model_id' => ['required_with:compatibilities', 'uuid'],
            'compatibilities.*.variant' => ['nullable', 'string', 'max:100'],
            'compatibilities.*.year_from' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'compatibilities.*.year_to' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'compatibilities.*.position' => ['nullable', 'string', 'max:50'],
        ])->validate();

        return $this->resolveCompatibilityVehicles($validated, true);
    }

    private function persistRim(Product $product, array $v, bool $includeCompatibilities = true): void
    {
        ProductRimSpec::query()->updateOrCreate(['product_id' => $product->id], [
            'model' => $v['model'] ?? null,
            'rim_type' => $v['rim_type'],
            'diameter_inch' => $v['diameter_inch'],
            'width_inch' => $v['width_inch'],
            'bolt_holes' => $v['bolt_holes'],
            'pcd_mm' => $v['pcd_mm'],
            'center_bore_mm' => $v['center_bore_mm'] ?? null,
            'offset_mm' => $v['offset_mm'] ?? null,
            'material' => $v['material'] ?? null,
            'max_load_kg' => $v['max_load_kg'] ?? null,
            'compatible_tire_sizes' => $v['compatible_tire_sizes'] ?? null,
        ]);

        if ($includeCompatibilities && ! empty($v['compatibilities'])) {
            $this->persistCompatibilities($product, $v['compatibilities']);
        }
    }

    // --- Tire ---

    private function validateTire(array $input): array
    {
        $tenantId = $this->context->tenantId();
        $tireRefRule = fn (string $table) => Rule::exists($table, 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));

        $v = Validator::make($input, [
            // OTR = OTR / Heavy Equipment — the third tire category the used-tire inspection rules distinguish.
            'vehicle_group' => ['required', 'in:CAR,TRUCK_BUS,OTR'],
            'pattern_name' => ['required', 'string', 'max:150'],
            'width_mm' => ['required', 'integer', 'min:1'],
            'aspect_ratio_percent' => ['required', 'integer', 'min:1'],
            'construction_type' => ['required', 'in:RADIAL,BIAS'],
            'rim_diameter_inch' => ['required', 'numeric', 'min:1'],
            'tire_type' => ['required', 'in:TUBELESS,TUBE_TYPE'],
            'single_load_index_id' => ['required', 'uuid', $tireRefRule('tire_load_indices')],
            'speed_rating_id' => ['required', 'uuid', $tireRefRule('tire_speed_ratings')],
            'dual_load_index_id' => ['nullable', 'uuid', $tireRefRule('tire_load_indices')],
            'ply_rating_id' => ['nullable', 'uuid', $tireRefRule('tire_ply_ratings')],
            'tra_code_id' => ['nullable', 'uuid', $tireRefRule('tire_tra_codes')],
            'tra_star_rating_id' => ['nullable', 'uuid', Rule::exists('tire_tra_star_ratings', 'id')],
        ])->validate();

        if (in_array($v['vehicle_group'], ['TRUCK_BUS', 'OTR'], true)) {
            // Required for Truck & Bus; optional (but kept when given) for OTR / Heavy Equipment.
            if ($v['vehicle_group'] === 'TRUCK_BUS' && empty($v['dual_load_index_id'])) {
                throw ValidationException::withMessages(['dual_load_index_id' => 'Dual Load Index is required for Truck & Bus tires.']);
            }
            if ($v['vehicle_group'] === 'TRUCK_BUS' && empty($v['ply_rating_id'])) {
                throw ValidationException::withMessages(['ply_rating_id' => 'Ply Rating is required for Truck & Bus tires.']);
            }
            $v['dual_load_index_id'] = $v['dual_load_index_id'] ?? null;
            $v['ply_rating_id'] = $v['ply_rating_id'] ?? null;
            // TRA Code/Star Rating are genuinely optional for Truck & Bus — unlike
            // every other field here, Laravel's validate() omits them from $v
            // entirely when the client doesn't send them at all (not merely null),
            // so they must be defaulted explicitly, the same way the Car branch
            // below defaults its four not-applicable fields. Without this,
            // persistTire()'s direct $v['tra_code_id']/$v['tra_star_rating_id']
            // access throws "Undefined array key" for a valid Truck & Bus tire
            // that legitimately has no TRA rating.
            $v['tra_code_id'] = $v['tra_code_id'] ?? null;
            $v['tra_star_rating_id'] = $v['tra_star_rating_id'] ?? null;
            // Star Rating is Conditional Mandatory: "Aktif setelah TRA Code dipilih".
            if (! empty($v['tra_code_id']) && empty($v['tra_star_rating_id'])) {
                throw ValidationException::withMessages(['tra_star_rating_id' => 'Star Rating is required once a TRA Code is selected.']);
            }
            if (! empty($v['tra_star_rating_id'])) {
                $star = TireTraStarRating::query()->find($v['tra_star_rating_id']);
                if (! $star || $star->tra_code_id !== ($v['tra_code_id'] ?? null)) {
                    throw ValidationException::withMessages(['tra_star_rating_id' => 'The selected Star Rating does not belong to the selected TRA Code.']);
                }
            }
        } else {
            // Not applicable to Car — never persisted even if the client sends them.
            $v['dual_load_index_id'] = null;
            $v['ply_rating_id'] = null;
            $v['tra_code_id'] = null;
            $v['tra_star_rating_id'] = null;
        }

        return $v;
    }

    private function persistTire(Product $product, array $v): void
    {
        $singleLoadIndex = TireLoadIndex::query()->findOrFail($v['single_load_index_id']);
        $speedRating = TireSpeedRating::query()->findOrFail($v['speed_rating_id']);

        $diameter = rtrim(rtrim(number_format((float) $v['rim_diameter_inch'], 1, '.', ''), '0'), '.');
        $tireSize = "{$v['width_mm']}/{$v['aspect_ratio_percent']} R{$diameter}";

        $derived = [
            'tire_size_computed' => $tireSize,
            'single_max_load_kg_computed' => $singleLoadIndex->max_load_single_kg,
            'max_speed_kmh_computed' => $speedRating->max_speed_kmh,
            'dual_max_load_kg_computed' => null,
            'load_range_computed' => null,
            'tra_profile_computed' => null,
            'purpose_computed' => null,
        ];

        if (in_array($v['vehicle_group'], ['TRUCK_BUS', 'OTR'], true)) {
            if (! empty($v['dual_load_index_id'])) {
                $derived['dual_max_load_kg_computed'] = TireLoadIndex::query()->findOrFail($v['dual_load_index_id'])->max_load_dual_kg;
            }
            if (! empty($v['ply_rating_id'])) {
                $derived['load_range_computed'] = TirePlyRating::query()->findOrFail($v['ply_rating_id'])->load_range;
            }

            if (! empty($v['tra_code_id'])) {
                $traCode = TireTraCode::query()->findOrFail($v['tra_code_id']);
                $derived['tra_profile_computed'] = $traCode->profile;
            }
            if (! empty($v['tra_star_rating_id'])) {
                $star = TireTraStarRating::query()->findOrFail($v['tra_star_rating_id']);
                $derived['purpose_computed'] = $star->purpose;
            }
        }

        ProductTireSpec::query()->updateOrCreate(['product_id' => $product->id], array_merge([
            'vehicle_group' => $v['vehicle_group'],
            'pattern_name' => $v['pattern_name'],
            'width_mm' => $v['width_mm'],
            'aspect_ratio_percent' => $v['aspect_ratio_percent'],
            'construction_type' => $v['construction_type'],
            'rim_diameter_inch' => $v['rim_diameter_inch'],
            'tire_type' => $v['tire_type'],
            'single_load_index_id' => $v['single_load_index_id'],
            'speed_rating_id' => $v['speed_rating_id'],
            'dual_load_index_id' => $v['dual_load_index_id'],
            'ply_rating_id' => $v['ply_rating_id'],
            'tra_code_id' => $v['tra_code_id'],
            'tra_star_rating_id' => $v['tra_star_rating_id'],
        ], $derived));
    }

    // --- Tool ---

    private function validateTool(array $input): array
    {
        $tenantId = $this->context->tenantId();
        $v = Validator::make($input, [
            'model' => ['nullable', 'string', 'max:150'],
            'tool_type_id' => ['required', 'uuid', Rule::exists('tool_types', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'specification' => ['nullable', 'string'],
            'checkout_required' => ['required', 'boolean'],
            'calibration_required' => ['required', 'boolean'],
            'calibration_interval_value' => ['nullable', 'integer', 'min:1'],
            'calibration_interval_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            'maintenance_required' => ['required', 'boolean'],
            'maintenance_interval_value' => ['nullable', 'integer', 'min:1'],
            'maintenance_interval_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
        ])->validate();

        if ($v['calibration_required'] && empty($v['calibration_interval_value'])) {
            throw ValidationException::withMessages(['calibration_interval_value' => 'Calibration Interval is required when Calibration is required.']);
        }
        if ($v['maintenance_required'] && empty($v['maintenance_interval_value'])) {
            throw ValidationException::withMessages(['maintenance_interval_value' => 'Maintenance Interval is required when Maintenance is required.']);
        }

        return $v;
    }

    private function persistTool(Product $product, array $v): void
    {
        ProductToolSpec::query()->updateOrCreate(['product_id' => $product->id], [
            'model' => $v['model'] ?? null,
            'tool_type_id' => $v['tool_type_id'],
            'specification' => $v['specification'] ?? null,
            'checkout_required' => $v['checkout_required'],
            'calibration_required' => $v['calibration_required'],
            'calibration_interval_value' => $v['calibration_interval_value'] ?? null,
            'calibration_interval_unit' => $v['calibration_interval_unit'] ?? null,
            'maintenance_required' => $v['maintenance_required'],
            'maintenance_interval_value' => $v['maintenance_interval_value'] ?? null,
            'maintenance_interval_unit' => $v['maintenance_interval_unit'] ?? null,
        ]);
    }

    // --- Equipment ---

    private function validateEquipment(array $input): array
    {
        $tenantId = $this->context->tenantId();
        $v = Validator::make($input, [
            'model' => ['required', 'string', 'max:150'],
            'equipment_type_id' => ['required', 'uuid', Rule::exists('equipment_types', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'specification' => ['nullable', 'string'],
            'capacity_value' => ['nullable', 'numeric', 'min:0'],
            'capacity_uom_id' => ['nullable', 'uuid', $this->uomExistsRule()],
            'power_source' => ['nullable', 'in:ELECTRIC,HYDRAULIC,PNEUMATIC,FUEL,MANUAL'],
            'power_rating_value' => ['nullable', 'numeric', 'min:0'],
            'power_rating_unit' => ['nullable', 'in:KW,HP'],
            'voltage_v' => ['nullable', 'integer', 'min:0'],
            'maintenance_required' => ['required', 'boolean'],
            'maintenance_interval_value' => ['nullable', 'integer', 'min:1'],
            'maintenance_interval_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            'inspection_required' => ['required', 'boolean'],
            'inspection_interval_value' => ['nullable', 'integer', 'min:1'],
            'inspection_interval_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            'calibration_required' => ['required', 'boolean'],
            'calibration_interval_value' => ['nullable', 'integer', 'min:1'],
            'calibration_interval_unit' => ['nullable', 'in:'.self::INTERVAL_UNITS],
            'certification_required' => ['nullable', 'boolean'],
            'certification_type' => ['nullable', 'string', 'max:150'],
        ])->validate();

        foreach (['maintenance', 'inspection', 'calibration'] as $flag) {
            if ($v["{$flag}_required"] && empty($v["{$flag}_interval_value"])) {
                throw ValidationException::withMessages(["{$flag}_interval_value" => ucfirst($flag)." Interval is required when {$flag} is required."]);
            }
        }
        if (! empty($v['certification_required']) && empty($v['certification_type'])) {
            throw ValidationException::withMessages(['certification_type' => 'Certification Type is required when Certification is required.']);
        }

        return $v;
    }

    private function persistEquipment(Product $product, array $v): void
    {
        ProductEquipmentSpec::query()->updateOrCreate(['product_id' => $product->id], $v);
    }

    // --- Shared helpers ---

    /** Resolves each compatibility row's Brand / Model ids into the reference + name snapshot. */
    private function resolveCompatibilityVehicles(array $validated, bool $required): array
    {
        if (empty($validated['compatibilities'])) {
            return $validated;
        }

        $resolver = app(VehicleMasterResolver::class);
        foreach ($validated['compatibilities'] as $i => $row) {
            $validated['compatibilities'][$i] = array_merge($row, $resolver->resolve(
                $row['vehicle_brand_id'] ?? null, $row['vehicle_model_id'] ?? null, $this->context->tenantId(), "compatibilities.{$i}.", $required,
            ));
        }

        return $validated;
    }

    private function persistCompatibilities(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            ProductCompatibility::query()->create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'component_group_id' => $row['component_group_id'] ?? null,
                'vehicle_category_id' => $row['vehicle_category_id'] ?? null,
                'vehicle_brand_id' => $row['vehicle_brand_id'] ?? null,
                'vehicle_brand' => $row['vehicle_brand'] ?? null,
                'vehicle_model_id' => $row['vehicle_model_id'] ?? null,
                'vehicle_model' => $row['vehicle_model'] ?? null,
                'variant' => $row['variant'] ?? null,
                'year_from' => $row['year_from'] ?? null,
                'year_to' => $row['year_to'] ?? null,
                'position' => $row['position'] ?? null,
            ]);
        }
    }

    private function uomExistsRule(): \Illuminate\Validation\Rules\Exists
    {
        $tenantId = $this->context->tenantId();

        return Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
