<?php

namespace App\Domain\ProductMaster\Imports;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\ComponentSubcategory;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\MasterData\Services\ComponentClassificationService;
use App\Domain\ProductMaster\Models\EquipmentType;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\StorageRequirement;
use App\Domain\ProductMaster\Models\ToolType;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\ProductMaster\Services\ProductCreationService;
use App\Domain\Shared\Support\Messages;
use App\Support\Spreadsheet\Import\ExcelImportDefinition;
use App\Support\Spreadsheet\Import\IdentifiesTemplate;
use App\Support\Spreadsheet\Import\ImportColumn;
use App\Support\Spreadsheet\Import\ImportRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Item-Type-specific Product Excel import. One template per Item Type: the common New Product fields
 * (StoreProductRequest) + that Item Type's dynamic specification fields (ProductSpecificationService).
 * The template carries its Item Type as a marker on "How To" row 2, so a template of another Item Type
 * is rejected. References are given by CODE and resolved with the master architecture (tenant record
 * wins over a platform record with the same code). Each row is then checked with ProductCreationService
 * ::check() — exactly the Create Product rules (Item Type ↔ category, Component Group → Category →
 * Subcategory, mandatory / conditional specification, brand / model compatibility, UOM) — and created
 * with ProductCreationService::create() (server-generated Item Code and SKU).
 */
class ProductImport implements ExcelImportDefinition, IdentifiesTemplate
{
    public const ITEM_TYPES = ['SPARE_PART', 'CONSUMABLE', 'RIM', 'TIRE', 'TOOL', 'EQUIPMENT'];

    public const MARKER_PREFIX = 'OPTIFLEET-PRODUCT-TEMPLATE:';

    private const INTERVAL_UNITS = 'DAYS, WEEKS, MONTHS, YEARS';

    /** @var array<string, array<string, ?string>> reference cache: kind → UPPER(code) → id */
    private array $refs = [];

    public function __construct(private readonly string $tenantId, private readonly string $itemType) {}

    public static function itemTypeFromMarker(?string $marker): ?string
    {
        if ($marker === null || ! str_starts_with($marker, self::MARKER_PREFIX)) {
            return null;
        }
        $type = substr($marker, strlen(self::MARKER_PREFIX));

        return in_array($type, self::ITEM_TYPES, true) ? $type : null;
    }

    public function marker(): string
    {
        return self::MARKER_PREFIX.$this->itemType;
    }

    public function markerMismatchMessage(?string $found): string
    {
        $foundType = self::itemTypeFromMarker($found);

        return $foundType
            ? Messages::localized('productImport.errors.wrongItemType', ['found' => $foundType, 'expected' => $this->itemType])
            : Messages::localized('productImport.errors.notProductTemplate', ['expected' => $this->itemType]);
    }

    /** [id, required, format, maxLength, helpKey?, helpParams?] — common fields, then the Item Type's spec fields. */
    private function columnSpecs(): array
    {
        $t = $this->itemType;
        $classified = in_array($t, ComponentClassificationService::CATEGORY_REQUIRED_ITEM_TYPES, true);
        $common = [
            ['name', true, ImportColumn::TEXT, 255, null],
            ['product_category_code', true, ImportColumn::TEXT, 50, 'productImport.help.productCategoryCode'],
            ['uom_code', true, ImportColumn::TEXT, 50, 'productImport.help.uomCode'],
            ['storage_bin', true, ImportColumn::TEXT, 200, 'productImport.help.storageBin'],
            ['component_group_code', $classified, ImportColumn::TEXT, 50, 'productImport.help.componentGroupCode'],
            ['component_category_code', $classified, ImportColumn::TEXT, 50, 'productImport.help.componentCategoryCode'],
            ['component_subcategory_code', false, ImportColumn::TEXT, 50, null],
            ['brand', in_array($t, ['SPARE_PART', 'RIM', 'TIRE', 'EQUIPMENT'], true), ImportColumn::TEXT, 100, null],
            ['manufacturer', false, ImportColumn::TEXT, 150, null],
            ['manufacturer_part_number', false, ImportColumn::TEXT, 100, null],
            ['description', false, ImportColumn::TEXT, 2000, null],
            ['weight_kg', false, ImportColumn::NUMBER, 30, null],
        ];
        if (in_array($t, ['SPARE_PART', 'RIM', 'TOOL', 'EQUIPMENT'], true)) {
            $common[] = ['track_serial_number', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'];
        }
        if ($t === 'CONSUMABLE') {
            $common[] = ['track_batch', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'];
        }
        $interval = fn (string $prefix, string $condition) => [
            ["{$prefix}_value", false, ImportColumn::INTEGER, 6, 'productImport.help.intervalValue', ['condition' => $condition]],
            ["{$prefix}_unit", false, ImportColumn::TEXT, 10, 'productImport.help.intervalUnit', ['units' => self::INTERVAL_UNITS]],
        ];
        $spec = match ($t) {
            'SPARE_PART' => [
                ['part_number', true, ImportColumn::TEXT, 100, null],
                ['part_type', true, ImportColumn::TEXT, 20, 'productImport.help.enum', ['values' => 'GENUINE, OEM, OES, AFTERMARKET']],
                ['oem_part_number', false, ImportColumn::TEXT, 100, null],
                ['specification', false, ImportColumn::TEXT, 2000, null],
                ['critical_part', false, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ...$interval('warranty_period', 'Warranty'),
                ['warranty_mileage_km', false, ImportColumn::INTEGER, 12, null],
                ['compatibilities', true, ImportColumn::TEXT, 2000, 'productImport.help.compatibilities'],
            ],
            'CONSUMABLE' => [
                ['grade_specification', false, ImportColumn::TEXT, 255, 'productImport.help.gradeSpecification'],
                ['package_size_value', false, ImportColumn::NUMBER, 30, null],
                ['package_size_uom_code', false, ImportColumn::TEXT, 50, 'productImport.help.uomCode'],
                ['track_expiry', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ['shelf_life_value', false, ImportColumn::INTEGER, 6, 'productImport.help.shelfLife'],
                ['shelf_life_unit', false, ImportColumn::TEXT, 10, 'productImport.help.intervalUnit', ['units' => self::INTERVAL_UNITS]],
                ['is_hazardous', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ['storage_requirement_codes', false, ImportColumn::TEXT, 500, 'productImport.help.storageRequirements'],
            ],
            'RIM' => [
                ['model', false, ImportColumn::TEXT, 100, null],
                ['rim_type', true, ImportColumn::TEXT, 10, 'productImport.help.enum', ['values' => 'STEEL, ALLOY, FORGED']],
                ['diameter_inch', true, ImportColumn::NUMBER, 10, null],
                ['width_inch', true, ImportColumn::NUMBER, 10, null],
                ['bolt_holes', true, ImportColumn::INTEGER, 3, null],
                ['pcd_mm', true, ImportColumn::NUMBER, 10, null],
                ['center_bore_mm', false, ImportColumn::NUMBER, 10, null],
                ['offset_mm', false, ImportColumn::NUMBER, 10, null],
                ['material', false, ImportColumn::TEXT, 100, null],
                ['max_load_kg', false, ImportColumn::NUMBER, 12, null],
            ],
            'TIRE' => [
                ['vehicle_group', true, ImportColumn::TEXT, 10, 'productImport.help.enum', ['values' => 'CAR, TRUCK_BUS, OTR']],
                ['pattern_name', true, ImportColumn::TEXT, 150, null],
                ['width_mm', true, ImportColumn::INTEGER, 5, null],
                ['aspect_ratio_percent', true, ImportColumn::INTEGER, 3, null],
                ['construction_type', true, ImportColumn::TEXT, 10, 'productImport.help.enum', ['values' => 'RADIAL, BIAS']],
                ['rim_diameter_inch', true, ImportColumn::NUMBER, 10, null],
                ['tire_type', true, ImportColumn::TEXT, 10, 'productImport.help.enum', ['values' => 'TUBELESS, TUBE_TYPE']],
                ['single_load_index', true, ImportColumn::TEXT, 10, 'productImport.help.loadIndex'],
                ['speed_rating', true, ImportColumn::TEXT, 5, 'productImport.help.speedRating'],
                ['dual_load_index', false, ImportColumn::TEXT, 10, 'productImport.help.dualLoadIndex'],
                ['ply_rating', false, ImportColumn::TEXT, 10, 'productImport.help.plyRating'],
                ['tra_code', false, ImportColumn::TEXT, 20, null],
                ['reference_tread_depth_mm', false, ImportColumn::NUMBER, 10, null],
            ],
            'TOOL' => [
                ['model', false, ImportColumn::TEXT, 150, null],
                ['tool_type_code', true, ImportColumn::TEXT, 50, null],
                ['specification', false, ImportColumn::TEXT, 2000, null],
                ['checkout_required', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ['calibration_required', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ...$interval('calibration_interval', 'Calibration'),
                ['maintenance_required', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ...$interval('maintenance_interval', 'Maintenance'),
            ],
            'EQUIPMENT' => [
                ['model', true, ImportColumn::TEXT, 150, null],
                ['equipment_type_code', true, ImportColumn::TEXT, 50, null],
                ['specification', false, ImportColumn::TEXT, 2000, null],
                ['capacity_value', false, ImportColumn::NUMBER, 30, null],
                ['capacity_uom_code', false, ImportColumn::TEXT, 50, 'productImport.help.uomCode'],
                ['power_source', false, ImportColumn::TEXT, 20, 'productImport.help.enum', ['values' => 'ELECTRIC, HYDRAULIC, PNEUMATIC, FUEL, MANUAL']],
                ['maintenance_required', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ...$interval('maintenance_interval', 'Maintenance'),
                ['inspection_required', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ...$interval('inspection_interval', 'Inspection'),
                ['calibration_required', true, ImportColumn::BOOLEAN, 5, 'productImport.help.yesNo'],
                ...$interval('calibration_interval', 'Calibration'),
            ],
        };

        return [...$common, ...$spec];
    }

    public function columns(): array
    {
        return array_map(fn (array $c) => new ImportColumn($c[0], 'productImport.columns.'.Str::camel($c[0]), $c[1], $c[2], $c[3], $c[4] ?? null, $c[5] ?? [], max(14, min(28, strlen($c[0]) + 4))), $this->columnSpecs());
    }

    public function title(string $locale): string
    {
        return Messages::text('productImport.title', ['itemType' => $this->itemType], $locale);
    }

    public function instructions(string $locale): array
    {
        $l = fn (string $key, array $params = []) => Messages::text($key, $params, $locale);

        return [
            $l('productImport.rules.itemType', ['itemType' => $this->itemType]),
            $l('productImport.rules.sameRules'),
            $l('productImport.rules.codes'),
            $l('productImport.rules.duplicate'),
            $l('productImport.rules.generated'),
        ];
    }

    public function examples(): array
    {
        $common = ['product_category_code' => 'PC-EXAMPLE', 'uom_code' => 'PCS', 'storage_bin' => 'WH-JKT/Z1/R1/B01', 'component_group_code' => 'CG-TYRE', 'component_category_code' => 'STEEL_RIM'];

        return [match ($this->itemType) {
            'SPARE_PART' => ['name' => 'Brake Pad Front', 'brand' => 'Bendix', 'component_group_code' => 'CG-BRAKE', 'component_category_code' => 'BRAKE_PAD', 'track_serial_number' => 'No', 'part_number' => 'DB1234', 'part_type' => 'AFTERMARKET', 'compatibilities' => 'HINO:H500; ISUZU:ELF'] + $common,
            'CONSUMABLE' => ['name' => 'Engine Oil 15W-40', 'brand' => 'Shell', 'uom_code' => 'L', 'component_group_code' => 'CG-ENGINE', 'component_category_code' => 'ENGINE_OIL', 'track_batch' => 'Yes', 'track_expiry' => 'Yes', 'shelf_life_value' => '24', 'shelf_life_unit' => 'MONTHS', 'is_hazardous' => 'No'] + $common,
            'RIM' => ['name' => 'Steel Rim 22.5 x 8.25', 'brand' => 'Accuride', 'track_serial_number' => 'Yes', 'rim_type' => 'STEEL', 'diameter_inch' => '22.5', 'width_inch' => '8.25', 'bolt_holes' => '10', 'pcd_mm' => '335', 'material' => 'Steel'] + $common,
            'TIRE' => ['name' => 'Bridgestone R150 11R22.5', 'brand' => 'Bridgestone', 'component_category_code' => 'TIRE', 'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'R150', 'width_mm' => '295', 'aspect_ratio_percent' => '80', 'construction_type' => 'RADIAL', 'rim_diameter_inch' => '22.5', 'tire_type' => 'TUBELESS', 'single_load_index' => '152', 'speed_rating' => 'M', 'dual_load_index' => '148', 'ply_rating' => '16PR'] + $common,
            'TOOL' => ['name' => 'Torque Wrench 1/2"', 'component_group_code' => '', 'component_category_code' => '', 'track_serial_number' => 'Yes', 'tool_type_code' => 'HAND_TOOL', 'checkout_required' => 'Yes', 'calibration_required' => 'Yes', 'calibration_interval_value' => '12', 'calibration_interval_unit' => 'MONTHS', 'maintenance_required' => 'No'] + $common,
            'EQUIPMENT' => ['name' => 'Hydraulic Jack 20T', 'brand' => 'Masada', 'component_group_code' => '', 'component_category_code' => '', 'track_serial_number' => 'Yes', 'model' => 'HJ-20', 'equipment_type_code' => 'LIFTING', 'maintenance_required' => 'No', 'inspection_required' => 'Yes', 'inspection_interval_value' => '6', 'inspection_interval_unit' => 'MONTHS', 'calibration_required' => 'No'] + $common,
        }];
    }

    public function validate(array $rows): array
    {
        ImportRows::repeatedInFile($rows, fn ($r) => $this->identity($r['values']), Messages::localized('productImport.columns.name'));
        $existing = $this->existingIdentities();
        $creation = app(ProductCreationService::class);
        foreach ($rows as &$row) {
            if (! ImportRows::open($row)) {
                continue;
            }
            if (isset($existing[Str::lower($this->identity($row['values']))])) {
                ImportRows::duplicate($row, Messages::localized('productImport.errors.productExists', ['name' => $row['values']['name'], 'itemType' => $this->itemType]));

                continue;
            }
            $input = $this->input($row);
            if (! ImportRows::open($row)) {
                continue;
            }
            try {
                $creation->check($this->tenantId, $input);
            } catch (ValidationException $e) {
                foreach (ImportRows::messages($e) as $message) {
                    ImportRows::invalid($row, $message);
                }
            }
        }

        return $rows;
    }

    public function persist(array $row): array
    {
        $input = $this->input($row);
        if ($row['errors'] !== []) {
            throw ValidationException::withMessages(['row' => $row['errors']]);
        }
        $product = app(ProductCreationService::class)->createFromInput($this->tenantId, $input);

        return ['id' => $product->id, 'label' => $product->code.' — '.$product->name];
    }

    /** "name|brand" — a product of this Item Type is a duplicate when both match (case-insensitive). */
    private function identity(array $values): ?string
    {
        return ($values['name'] ?? null) === null ? null : trim((string) $values['name']).'|'.trim((string) ($values['brand'] ?? ''));
    }

    /** @return array<string, true> */
    private function existingIdentities(): array
    {
        $out = [];
        Product::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereNull('deleted_at')
            ->where('product_type', $this->itemType)->get(['name', 'brand'])
            ->each(function ($p) use (&$out) {
                $out[Str::lower(trim($p->name).'|'.trim((string) $p->brand))] = true;
            });

        return $out;
    }

    /**
     * The Create Product request body for a row: references resolved from codes (an unknown code marks the
     * row INVALID). Boolean / number cells are already normalized by the engine.
     */
    private function input(array &$row): array
    {
        $v = $row['values'];
        $label = fn (string $id) => Messages::localized('productImport.columns.'.Str::camel($id));
        $ref = function (string $id, string $kind, ?string $parent = null) use (&$row, $v, $label) {
            $code = $v[$id] ?? null;
            if ($code === null || $code === '') {
                return null;
            }
            $resolved = $this->resolve($kind, (string) $code, $parent);
            if ($resolved === null) {
                ImportRows::invalid($row, Messages::localized('excelImport.errors.notFound', ['column' => $label($id), 'value' => $code]));
            }

            return $resolved;
        };

        $groupId = $ref('component_group_code', 'group');
        $categoryId = $groupId ? $ref('component_category_code', 'componentCategory', $groupId) : null;
        $input = [
            'product_type' => $this->itemType,
            'name' => $v['name'],
            'product_category_id' => $ref('product_category_code', 'productCategory'),
            'uom_id' => $ref('uom_code', 'uom'),
            'default_storage_bin_id' => $ref('storage_bin', 'bin'),
            'component_group_id' => $groupId,
            'component_category_id' => $categoryId,
            'component_subcategory_id' => $categoryId ? $ref('component_subcategory_code', 'componentSubcategory', $categoryId) : null,
            'brand' => $v['brand'] ?? null,
            'manufacturer' => $v['manufacturer'] ?? null,
            'manufacturer_part_number' => $v['manufacturer_part_number'] ?? null,
            'description' => $v['description'] ?? null,
            'weight_kg' => $v['weight_kg'] ?? null,
            'track_serial_number' => $v['track_serial_number'] ?? null,
            'track_batch' => $v['track_batch'] ?? null,
        ];
        if ($this->itemType === 'TIRE') {
            $input['reference_tread_depth_mm'] = $v['reference_tread_depth_mm'] ?? null;
        }

        $spec = [];
        $plain = ['part_number', 'part_type', 'oem_part_number', 'specification', 'critical_part', 'warranty_period_value', 'warranty_period_unit', 'warranty_mileage_km',
            'grade_specification', 'package_size_value', 'track_expiry', 'shelf_life_value', 'shelf_life_unit', 'is_hazardous',
            'model', 'rim_type', 'diameter_inch', 'width_inch', 'bolt_holes', 'pcd_mm', 'center_bore_mm', 'offset_mm', 'material', 'max_load_kg',
            'vehicle_group', 'pattern_name', 'width_mm', 'aspect_ratio_percent', 'construction_type', 'rim_diameter_inch', 'tire_type',
            'checkout_required', 'calibration_required', 'calibration_interval_value', 'calibration_interval_unit', 'maintenance_required', 'maintenance_interval_value', 'maintenance_interval_unit',
            'inspection_required', 'inspection_interval_value', 'inspection_interval_unit', 'capacity_value', 'power_source'];
        foreach ($plain as $key) {
            if (array_key_exists($key, $v)) {
                $spec[$key] = is_string($v[$key]) && in_array($key, ['part_type', 'warranty_period_unit', 'shelf_life_unit', 'rim_type', 'vehicle_group', 'construction_type', 'tire_type', 'calibration_interval_unit', 'maintenance_interval_unit', 'inspection_interval_unit', 'power_source'], true)
                    ? Str::upper($v[$key]) : $v[$key];
            }
        }
        $spec['package_size_uom_id'] = $ref('package_size_uom_code', 'uom');
        $spec['capacity_uom_id'] = $ref('capacity_uom_code', 'uom');
        $spec['tool_type_id'] = $ref('tool_type_code', 'toolType');
        $spec['equipment_type_id'] = $ref('equipment_type_code', 'equipmentType');
        $spec['single_load_index_id'] = $ref('single_load_index', 'loadIndex');
        $spec['dual_load_index_id'] = $ref('dual_load_index', 'loadIndex');
        $spec['speed_rating_id'] = $ref('speed_rating', 'speedRating');
        $spec['ply_rating_id'] = $ref('ply_rating', 'plyRating');
        $spec['tra_code_id'] = $ref('tra_code', 'traCode');
        if (! empty($v['storage_requirement_codes'])) {
            $spec['storage_requirement_ids'] = [];
            foreach (array_filter(array_map('trim', preg_split('/[;,]/', (string) $v['storage_requirement_codes']))) as $code) {
                $id = $this->resolve('storageRequirement', $code);
                $id ? $spec['storage_requirement_ids'][] = $id : ImportRows::invalid($row, Messages::localized('excelImport.errors.notFound', ['column' => $label('storage_requirement_codes'), 'value' => $code]));
            }
        }
        if ($this->itemType === 'SPARE_PART' && ! empty($v['compatibilities'])) {
            $spec['compatibilities'] = $this->compatibilities((string) $v['compatibilities'], $row);
        }

        return array_filter($input, fn ($x) => $x !== null && $x !== '') + ['spec' => array_filter($spec, fn ($x) => $x !== null && $x !== '')];
    }

    /** "HINO:H500; ISUZU:ELF" → [{vehicle_brand_id, vehicle_model_id}, …] (model looked up within its brand). */
    private function compatibilities(string $text, array &$row): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $text))) as $pair) {
            [$brandCode, $modelCode] = array_map('trim', explode(':', $pair, 2)) + [1 => ''];
            $brandId = $this->resolve('vehicleBrand', $brandCode);
            $modelId = $brandId && $modelCode !== '' ? $this->resolve('vehicleModel', $modelCode, $brandId) : null;
            if (! $brandId || ! $modelId) {
                ImportRows::invalid($row, Messages::localized('productImport.errors.compatibilityNotFound', ['pair' => $pair]));

                continue;
            }
            $out[] = ['vehicle_brand_id' => $brandId, 'vehicle_model_id' => $modelId];
        }

        return $out;
    }

    /** Code → id within the tenant (own record first, then platform), optionally inside a parent. */
    private function resolve(string $kind, string $code, ?string $parent = null): ?string
    {
        $key = Str::upper(trim($code));
        $cacheKey = $kind.'|'.$parent;
        if (isset($this->refs[$cacheKey]) && array_key_exists($key, $this->refs[$cacheKey])) {
            return $this->refs[$cacheKey][$key];
        }
        $tenantOrPlatform = fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id'));
        $byCode = fn ($q, string $column = 'code') => $q->whereRaw("upper({$column}) = ?", [$key])->orderByRaw('tenant_id IS NULL');

        $id = match ($kind) {
            'productCategory' => $byCode($tenantOrPlatform(ProductCategory::query()->withoutGlobalScopes()->whereNull('deleted_at')))->value('id'),
            'uom' => $byCode($tenantOrPlatform(Uom::query()->withoutGlobalScopes()->whereNull('deleted_at')))->value('id'),
            'group' => $tenantOrPlatform(ComponentGroup::query()->withoutGlobalScopes()->whereNull('deleted_at'))
                ->where(fn ($q) => $q->whereRaw('upper(code) = ?', [$key])->orWhereRaw('upper(abbreviation) = ?', [$key]))->orderByRaw('tenant_id IS NULL')->value('id'),
            'componentCategory' => $byCode($tenantOrPlatform(ComponentCategory::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('component_group_id', $parent)))->value('id'),
            'componentSubcategory' => $byCode($tenantOrPlatform(ComponentSubcategory::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('component_category_id', $parent)))->value('id'),
            'toolType' => $byCode($tenantOrPlatform(ToolType::query()->withoutGlobalScopes()->whereNull('deleted_at')))->value('id'),
            'equipmentType' => $byCode($tenantOrPlatform(EquipmentType::query()->withoutGlobalScopes()->whereNull('deleted_at')))->value('id'),
            'storageRequirement' => $byCode($tenantOrPlatform(StorageRequirement::query()->withoutGlobalScopes()->whereNull('deleted_at')))->value('id'),
            'loadIndex' => $byCode($tenantOrPlatform(DB::table('tire_load_indices')))->value('id'),
            'speedRating' => $byCode($tenantOrPlatform(DB::table('tire_speed_ratings')))->value('id'),
            'plyRating' => $byCode($tenantOrPlatform(DB::table('tire_ply_ratings')))->value('id'),
            'traCode' => $byCode($tenantOrPlatform(DB::table('tire_tra_codes')))->value('id'),
            'vehicleBrand' => $byCode($tenantOrPlatform(VehicleBrand::query()->withoutGlobalScopes()->whereNull('deleted_at')))->value('id'),
            'vehicleModel' => $byCode($tenantOrPlatform(VehicleModel::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('vehicle_brand_id', $parent)))->value('id'),
            'bin' => $this->bin($key),
        };

        return $this->refs[$cacheKey][$key] = $id;
    }

    /** "WH-JKT/Z1/R1/B01" → the bin of that warehouse → zone → rack (codes, case-insensitive). */
    private function bin(string $path): ?string
    {
        $parts = array_map('trim', explode('/', $path));
        if (count($parts) !== 4 || in_array('', $parts, true)) {
            return null;
        }
        [$warehouse, $zone, $rack, $bin] = $parts;

        return DB::table('warehouse_bins as b')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'r.warehouse_zone_id')
            ->join('warehouses as w', 'w.id', '=', 'z.warehouse_id')
            ->where('w.tenant_id', $this->tenantId)->whereNull('w.deleted_at')
            ->whereRaw('upper(w.code) = ?', [$warehouse])->whereRaw('upper(z.code) = ?', [$zone])
            ->whereRaw('upper(r.code) = ?', [$rack])->whereRaw('upper(b.code) = ?', [$bin])
            ->value('b.id');
    }
}
