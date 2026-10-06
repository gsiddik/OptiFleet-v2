<?php

namespace App\Domain\Vehicle\Imports;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityExceededException;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\Organization\Models\Branch;
use App\Domain\Shared\Support\Messages;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\VehicleCreationService;
use App\Models\User;
use App\Support\Spreadsheet\Import\ExcelImportDefinition;
use App\Support\Spreadsheet\Import\ImportColumn;
use App\Support\Spreadsheet\Import\ImportRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle Excel import. The columns are the New Vehicle form's fields (mandatory: Branch, Category,
 * Brand, Model, Registration Number — the StoreVehicleRequest required rules; optional: VIN, Current
 * Odometer, Purchase Month / Year). The New Vehicle form has no fields that depend on the vehicle type,
 * so every row is checked with the same rules. Master references are given by CODE (names may repeat):
 * a tenant's own record wins over a platform record with the same code; a model code is looked up within
 * its brand, so an invalid Brand/Model combination is reported. Each row is then validated with exactly
 * the StoreVehicleRequest rules and created through VehicleCreationService (capacity, branch scope).
 */
class VehicleImport implements ExcelImportDefinition
{
    public function __construct(private readonly string $tenantId, private readonly User $user) {}

    public function columns(): array
    {
        return [
            new ImportColumn('branch_code', 'vehicle.import.columnBranchCode', true, ImportColumn::TEXT, 50, 'vehicle.import.helpBranchCode', [], 16),
            new ImportColumn('vehicle_category_code', 'vehicle.import.columnCategoryCode', true, ImportColumn::TEXT, 50, 'vehicle.import.helpCategoryCode', [], 20),
            new ImportColumn('vehicle_brand_code', 'vehicle.import.columnBrandCode', true, ImportColumn::TEXT, 50, 'vehicle.import.helpBrandCode', [], 16),
            new ImportColumn('vehicle_model_code', 'vehicle.import.columnModelCode', true, ImportColumn::TEXT, 50, 'vehicle.import.helpModelCode', [], 18),
            new ImportColumn('registration_number', 'vehicle.import.columnRegistrationNumber', true, ImportColumn::TEXT, 50, 'vehicle.import.helpRegistrationNumber', [], 22),
            new ImportColumn('vin', 'vehicle.import.columnVin', false, ImportColumn::TEXT, 50, 'vehicle.import.helpVin', [], 22),
            new ImportColumn('current_odometer', 'vehicle.import.columnCurrentOdometer', false, ImportColumn::NUMBER, 30, 'vehicle.import.helpCurrentOdometer', [], 18),
            new ImportColumn('purchase_month', 'vehicle.import.columnPurchaseMonth', false, ImportColumn::INTEGER, 2, 'vehicle.import.helpPurchaseMonth', [], 16),
            new ImportColumn('purchase_year', 'vehicle.import.columnPurchaseYear', false, ImportColumn::INTEGER, 4, 'vehicle.import.helpPurchaseYear', ['maxYear' => (int) date('Y') + 1], 16),
        ];
    }

    public function title(string $locale): string
    {
        return Messages::text('vehicle.import.title', [], $locale);
    }

    public function instructions(string $locale): array
    {
        $l = fn (string $key, array $params = []) => Messages::text($key, $params, $locale);
        $lines = [$l('vehicle.import.ruleRegistrationUnique'), $l('vehicle.import.ruleCodes'), $l('vehicle.import.ruleBranchScope')];
        $lines[] = $l('vehicle.import.referenceBranches', ['values' => $this->referenceList($this->branches())]);
        $lines[] = $l('vehicle.import.referenceCategories', ['values' => $this->referenceList($this->categories())]);
        $brands = $this->brands();
        $models = VehicleModel::query()->withoutGlobalScopes()->whereIn('vehicle_brand_id', $brands->pluck('id'))->whereNull('deleted_at')->orderBy('code')->get(['vehicle_brand_id', 'code', 'name'])->groupBy('vehicle_brand_id');
        foreach ($brands->take(100) as $brand) {
            $lines[] = $l('vehicle.import.referenceBrandModels', [
                'brand' => "{$brand->code} — {$brand->name}",
                'values' => $this->referenceList($models->get($brand->id, collect())),
            ]);
        }

        return $lines;
    }

    public function examples(): array
    {
        $branch = $this->branches()->first();
        $category = $this->categories()->first();
        $brand = $this->brands()->first();
        $model = $brand ? VehicleModel::query()->withoutGlobalScopes()->where('vehicle_brand_id', $brand->id)->whereNull('deleted_at')->orderBy('code')->first() : null;
        $base = [
            'branch_code' => $branch?->code ?? 'BR-JKT',
            'vehicle_category_code' => $category?->code ?? 'VC-TRUCK',
            'vehicle_brand_code' => $brand?->code ?? 'HINO',
            'vehicle_model_code' => $model?->code ?? 'HINO-500',
        ];

        return [
            $base + ['registration_number' => 'B 9001 TXA', 'vin' => 'MHKA1BA1JFK000001', 'current_odometer' => '125000', 'purchase_month' => '7', 'purchase_year' => '2024'],
            $base + ['registration_number' => 'B 9002 TXA', 'vin' => '', 'current_odometer' => '0', 'purchase_month' => '', 'purchase_year' => ''],
        ];
    }

    public function validate(array $rows): array
    {
        $label = fn (string $key) => Messages::localized($key);
        ImportRows::repeatedInFile($rows, fn ($r) => $this->plate($r['values']['registration_number'] ?? null), $label('vehicle.import.columnRegistrationNumber'));
        ImportRows::repeatedInFile($rows, fn ($r) => $r['values']['vin'] ?? null, $label('vehicle.import.columnVin'));

        $branches = $this->branches()->keyBy(fn ($b) => Str::upper($b->code));
        $categories = $this->categories()->keyBy(fn ($c) => Str::upper($c->code));
        $brands = $this->brands()->keyBy(fn ($b) => Str::upper($b->code));
        $plates = $this->existingPlates(array_map(fn ($r) => $r['values']['registration_number'] ?? null, $rows));
        $scope = app(DataScopeService::class);
        $creation = app(VehicleCreationService::class);

        foreach ($rows as &$row) {
            if (! ImportRows::open($row)) {
                continue;
            }
            $v = $row['values'];
            if (isset($plates[$this->plate($v['registration_number'])])) {
                ImportRows::duplicate($row, Messages::localized('excelImport.errors.alreadyExists', ['column' => $label('vehicle.import.columnRegistrationNumber'), 'value' => $v['registration_number']]));

                continue;
            }
            $branch = $branches->get(Str::upper($v['branch_code']));
            $category = $categories->get(Str::upper($v['vehicle_category_code']));
            $brand = $brands->get(Str::upper($v['vehicle_brand_code']));
            if (! $branch) {
                ImportRows::invalid($row, Messages::localized('excelImport.errors.notFound', ['column' => $label('vehicle.import.columnBranchCode'), 'value' => $v['branch_code']]));
            } elseif (! $scope->canAccessBranch($this->user, $this->tenantId, $branch->id)) {
                ImportRows::invalid($row, Messages::localized('vehicle.import.branchOutOfScope'));
            }
            if (! $category) {
                ImportRows::invalid($row, Messages::localized('excelImport.errors.notFound', ['column' => $label('vehicle.import.columnCategoryCode'), 'value' => $v['vehicle_category_code']]));
            }
            $model = null;
            if (! $brand) {
                ImportRows::invalid($row, Messages::localized('excelImport.errors.notFound', ['column' => $label('vehicle.import.columnBrandCode'), 'value' => $v['vehicle_brand_code']]));
            } else {
                $model = $this->model($brand->id, $v['vehicle_model_code']);
                if (! $model) {
                    ImportRows::invalid($row, Messages::localized('vehicle.import.modelNotOfBrand', ['model' => $v['vehicle_model_code'], 'brand' => $brand->code]));
                }
            }
            if (! ImportRows::open($row)) {
                continue;
            }
            $row['resolved'] = $this->input($row, $branch->id, $category->id, $brand->id, $model->id);
            try {
                $creation->validate($row['resolved']);
            } catch (ValidationException $e) {
                foreach (ImportRows::messages($e) as $message) {
                    ImportRows::invalid($row, $message);
                }
            }
        }

        return array_map(function ($row) {
            unset($row['resolved']);

            return $row;
        }, $rows);
    }

    public function persist(array $row): array
    {
        // Resolved again from the codes (never from client ids) and validated with the New Vehicle rules.
        $v = $row['values'];
        $branch = $this->branches()->first(fn ($b) => Str::upper($b->code) === Str::upper($v['branch_code']));
        $category = $this->categories()->first(fn ($c) => Str::upper($c->code) === Str::upper($v['vehicle_category_code']));
        $brand = $this->brands()->first(fn ($b) => Str::upper($b->code) === Str::upper($v['vehicle_brand_code']));
        $model = $brand ? $this->model($brand->id, $v['vehicle_model_code']) : null;
        if (! $branch || ! $category || ! $brand || ! $model) {
            throw ValidationException::withMessages(['row' => Messages::localized('vehicle.import.referenceChanged')]);
        }
        $creation = app(VehicleCreationService::class);
        $validated = $creation->validate($this->input($row, $branch->id, $category->id, $brand->id, $model->id));
        try {
            $vehicle = $creation->create($this->tenantId, $validated, $this->user);
        } catch (CapacityExceededException $e) {
            throw ValidationException::withMessages(['row' => $e->getMessage()]);
        }

        return ['id' => $vehicle->id, 'label' => $vehicle->registration_number];
    }

    /** The New Vehicle request body for one row. */
    private function input(array $row, string $branchId, string $categoryId, string $brandId, string $modelId): array
    {
        $v = $row['values'];

        return array_filter([
            'branch_id' => $branchId,
            'vehicle_category_id' => $categoryId,
            'vehicle_brand_id' => $brandId,
            'vehicle_model_id' => $modelId,
            'registration_number' => $v['registration_number'],
            'vin' => $v['vin'] ?? null,
            'current_odometer' => $v['current_odometer'] ?? null,
            'purchase_month' => $v['purchase_month'] ?? null,
            'purchase_year' => $v['purchase_year'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function plate(?string $registration): string
    {
        return Str::upper(preg_replace('/\s+/', '', (string) $registration));
    }

    /** @return array<string, true> normalized plates already registered for the tenant */
    private function existingPlates(array $registrations): array
    {
        $plates = array_values(array_unique(array_filter(array_map(fn ($r) => $this->plate($r), $registrations))));
        $out = [];
        foreach (array_chunk($plates, 500) as $chunk) {
            Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)
                ->whereIn(DB::raw("upper(regexp_replace(registration_number, '\\s+', '', 'g'))"), $chunk)
                ->pluck('registration_number')->each(function ($p) use (&$out) {
                    $out[$this->plate($p)] = true;
                });
        }

        return $out;
    }

    private function branches(): Collection
    {
        return Branch::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** Tenant + platform records; a tenant record wins over a platform record with the same code. */
    private function tenantOrPlatform(string $model): Collection
    {
        return $model::query()->withoutGlobalScopes()->where(fn ($q) => $q->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id'))
            ->whereNull('deleted_at')->orderByRaw('tenant_id IS NULL')->orderBy('code')->get(['id', 'tenant_id', 'code', 'name'])
            ->unique(fn ($r) => Str::upper($r->code))->sortBy('code')->values();
    }

    private function categories(): Collection
    {
        return $this->tenantOrPlatform(VehicleCategory::class);
    }

    private function brands(): Collection
    {
        return $this->tenantOrPlatform(VehicleBrand::class);
    }

    private function model(string $brandId, string $code): ?VehicleModel
    {
        return VehicleModel::query()->withoutGlobalScopes()->where('vehicle_brand_id', $brandId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id'))
            ->whereRaw('upper(code) = ?', [Str::upper(trim($code))])->orderByRaw('tenant_id IS NULL')->first();
    }

    private function referenceList(Collection $records): string
    {
        $items = $records->take(200)->map(fn ($r) => "{$r->code} ({$r->name})")->all();

        return $items === [] ? '—' : implode(', ', $items);
    }
}
