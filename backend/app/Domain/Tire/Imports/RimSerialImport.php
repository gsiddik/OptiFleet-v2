<?php

namespace App\Domain\Tire\Imports;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use App\Domain\Tire\Services\RimRegistrationService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Models\User;
use App\Support\Spreadsheet\Import\ExcelImportDefinition;
use App\Support\Spreadsheet\Import\ImportColumn;
use App\Support\Spreadsheet\Import\ImportRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rim Serial Number import for one Rim Product (the product comes from the Rim Detail the user is on):
 *
 *   NEW_STOCK  Serial Number, Warehouse Code                   → IN_STOCK rims in that warehouse
 *   INSTALLED  Serial Number, Vehicle Registration, Position Code → rims installed on that position
 *
 * Every row is registered through RimRegistrationService (the Register Rim path); the checks below only
 * classify rows for the preview with the same rules: empty / repeated / registered serial, unknown or
 * out-of-scope vehicle, no wheels configuration, unknown position, position already holding a rim,
 * the same vehicle + position twice in the file.
 */
class RimSerialImport implements ExcelImportDefinition
{
    public const NEW_STOCK = 'NEW_STOCK';

    public const INSTALLED = 'INSTALLED';

    public const MODES = [self::NEW_STOCK, self::INSTALLED];

    public function __construct(
        private readonly string $tenantId,
        private readonly Product $product,
        private readonly string $mode,
        private readonly ?User $user,
    ) {}

    public function columns(): array
    {
        $columns = [new ImportColumn('serial_number', 'rim.import.columnSerialNumber', true, ImportColumn::TEXT, 100, 'rim.import.helpSerialNumber', [], 28)];
        if ($this->mode === self::NEW_STOCK) {
            // Mandatory: an IN_STOCK rim is always in a warehouse.
            $columns[] = new ImportColumn('warehouse_code', 'rim.import.columnWarehouseCode', true, ImportColumn::TEXT, 50, 'rim.import.helpWarehouseCode', [], 18);
        }
        if ($this->mode === self::INSTALLED) {
            $columns[] = new ImportColumn('vehicle_registration', 'rim.import.columnVehicleRegistration', true, ImportColumn::TEXT, 50, 'rim.import.helpVehicleRegistration', [], 24);
            $columns[] = new ImportColumn('position_code', 'rim.import.columnPositionCode', true, ImportColumn::TEXT, 50, 'rim.import.helpPositionCode', [], 18);
        }

        return $columns;
    }

    public function title(string $locale): string
    {
        return Messages::text($this->mode === self::INSTALLED ? 'rim.import.titleInstalled' : 'rim.import.titleNewStock', [
            'productName' => $this->product->name, 'productCode' => $this->product->code ?? '—',
        ], $locale);
    }

    public function instructions(string $locale): array
    {
        $lines = [
            Messages::text('rim.import.ruleProduct', ['productName' => $this->product->name], $locale),
            Messages::text('rim.import.ruleSerialUnique', [], $locale),
        ];
        if ($this->mode === self::INSTALLED) {
            $lines[] = Messages::text('rim.import.ruleVehicle', [], $locale);
            $lines[] = Messages::text('rim.import.rulePosition', [], $locale);
            $lines[] = Messages::text('rim.import.ruleTireCoexists', [], $locale);
        } else {
            $lines[] = Messages::text('rim.import.ruleNewStock', [], $locale);
        }

        return $lines;
    }

    public function examples(): array
    {
        return $this->mode === self::INSTALLED
            ? [
                ['serial_number' => 'RIM-22.5-000101', 'vehicle_registration' => 'B 9123 TXA', 'position_code' => 'FL'],
                ['serial_number' => 'RIM-22.5-000102', 'vehicle_registration' => 'B 9123 TXA', 'position_code' => 'FR'],
                ['serial_number' => 'RIM-22.5-000103', 'vehicle_registration' => 'B 9123 TXA', 'position_code' => 'RLO'],
            ]
            : [
                ['serial_number' => 'RIM-22.5-000201', 'warehouse_code' => 'WH-JKT'],
                ['serial_number' => 'RIM-22.5-000202', 'warehouse_code' => 'WH-JKT'],
                ['serial_number' => 'RIM-22.5-000203', 'warehouse_code' => 'WH-SBY'],
            ];
    }

    public function validate(array $rows): array
    {
        $serialLabel = Messages::localized('rim.import.columnSerialNumber');
        ImportRows::repeatedInFile($rows, fn ($r) => $r['values']['serial_number'] ?? null, $serialLabel);

        $existing = $this->existingSerials(array_filter(array_map(fn ($r) => $r['values']['serial_number'] ?? null, $rows)));
        foreach ($rows as &$row) {
            $serial = $row['values']['serial_number'] ?? null;
            if (ImportRows::open($row) && $serial !== null && isset($existing[Str::lower($serial)])) {
                ImportRows::duplicate($row, Messages::localized('rim.errors.serialExists', ['serial' => $serial]));
            }
        }
        unset($row);

        if ($this->mode === self::INSTALLED) {
            $this->validateInstalled($rows);
        } else {
            $this->validateWarehouses($rows);
        }

        return $rows;
    }

    public function persist(array $row): array
    {
        $data = ['serial_number' => $row['values']['serial_number']];
        if ($this->mode === self::NEW_STOCK) {
            $data['warehouse_id'] = $this->warehouses([$row['values']['warehouse_code']])->first()?->id
                ?? throw ValidationException::withMessages(['warehouse_id' => Messages::localized('rim.errors.warehouseNotFound', ['warehouse' => $row['values']['warehouse_code']])]);
        }
        if ($this->mode === self::INSTALLED) {
            $vehicle = $this->vehicles([$row['values']['vehicle_registration']])->first();
            if (! $vehicle) {
                throw ValidationException::withMessages(['vehicle_id' => Messages::localized('rim.errors.vehicleNotFound', ['vehicle' => $row['values']['vehicle_registration']])]);
            }
            $data['vehicle_id'] = $vehicle->id;
            $data['position_code'] = $row['values']['position_code'];
        }
        $asset = app(RimRegistrationService::class)->register($this->tenantId, $this->product, $data, $this->user);

        return ['id' => $asset->id, 'label' => $asset->serial_number];
    }

    private function validateWarehouses(array &$rows): void
    {
        $warehouses = $this->warehouses(array_filter(array_map(fn ($r) => $r['values']['warehouse_code'] ?? null, $rows)))
            ->keyBy(fn ($w) => Str::upper($w->code));
        $allowed = $this->user ? app(DataScopeService::class)->allowedWarehouseIds($this->user, $this->tenantId) : null;
        foreach ($rows as &$row) {
            if (! ImportRows::open($row)) {
                continue;
            }
            $code = (string) $row['values']['warehouse_code'];
            $warehouse = $warehouses->get(Str::upper(trim($code)));
            if (! $warehouse) {
                ImportRows::invalid($row, Messages::localized('rim.errors.warehouseNotFound', ['warehouse' => $code]));
            } elseif ($allowed !== null && ! in_array($warehouse->id, $allowed, true)) {
                ImportRows::invalid($row, Messages::localized('rim.errors.warehouseOutOfScope'));
            }
        }
    }

    /** Warehouses of the tenant by code (case-insensitive). */
    private function warehouses(array $codes)
    {
        $codes = array_values(array_unique(array_map(fn ($c) => Str::upper(trim($c)), $codes)));

        return $codes === [] ? collect() : DB::table('warehouses')->where('tenant_id', $this->tenantId)->whereNull('deleted_at')
            ->whereIn(DB::raw('upper(code)'), $codes)->get(['id', 'code']);
    }

    private function validateInstalled(array &$rows): void
    {
        $vehicles = $this->vehicles(array_filter(array_map(fn ($r) => $r['values']['vehicle_registration'] ?? null, $rows)))
            ->keyBy(fn (Vehicle $v) => $this->plate($v->registration_number));
        $scope = app(DataScopeService::class);
        $positions = $this->positionsOf($vehicles);
        $occupied = $this->occupiedRimPositions($vehicles->pluck('id')->all());
        $seen = [];

        foreach ($rows as &$row) {
            if (! ImportRows::open($row)) {
                continue;
            }
            $registration = (string) $row['values']['vehicle_registration'];
            $vehicle = $vehicles->get($this->plate($registration));
            if (! $vehicle) {
                ImportRows::invalid($row, Messages::localized('rim.errors.vehicleNotFound', ['vehicle' => $registration]));

                continue;
            }
            if ($this->user && ! $scope->canAccessBranch($this->user, $this->tenantId, (string) $vehicle->branch_id)) {
                ImportRows::invalid($row, Messages::localized('rim.errors.vehicleOutOfScope', ['vehicle' => $vehicle->registration_number]));

                continue;
            }
            if (! array_key_exists($vehicle->id, $positions)) {
                ImportRows::invalid($row, Messages::localized('rim.errors.noWheelConfiguration', ['vehicle' => $vehicle->registration_number]));

                continue;
            }
            $code = Str::upper(trim((string) $row['values']['position_code']));
            $position = $positions[$vehicle->id][$code] ?? null;
            if (! $position) {
                ImportRows::invalid($row, Messages::localized('rim.errors.invalidPosition', ['position' => $row['values']['position_code'], 'vehicle' => $vehicle->registration_number]));

                continue;
            }
            $row['values']['position_code'] = $position;
            if (isset($occupied[$vehicle->id][$position])) {
                ImportRows::invalid($row, Messages::localized('rim.errors.positionOccupied', ['position' => $position, 'vehicle' => $vehicle->registration_number, 'serial' => $occupied[$vehicle->id][$position]]));

                continue;
            }
            $key = $vehicle->id.'|'.$position;
            if (isset($seen[$key])) {
                ImportRows::duplicate($row, Messages::localized('rim.import.positionRepeatedInFile', ['position' => $position, 'vehicle' => $vehicle->registration_number, 'row' => $seen[$key]]));

                continue;
            }
            $seen[$key] = $row['row'];
        }
    }

    /** Registration numbers compare without case and spaces ("B 9123 TXA" = "b9123txa"). */
    private function plate(?string $registration): string
    {
        return Str::upper(preg_replace('/\s+/', '', (string) $registration));
    }

    private function vehicles(array $registrations)
    {
        $plates = array_values(array_unique(array_map(fn ($r) => $this->plate($r), $registrations)));

        return $plates === [] ? collect() : Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn(DB::raw("upper(regexp_replace(registration_number, '\\s+', '', 'g'))"), $plates)->get();
    }

    /** @return array<string, array<string, string>> vehicle id → UPPER(code) → code, for vehicles with a configuration */
    private function positionsOf($vehicles): array
    {
        $out = [];
        foreach ($vehicles as $vehicle) {
            $mapping = $vehicle->activeWheelConfigurationMapping()->withoutGlobalScopes()->first();
            if (! $mapping) {
                continue;
            }
            $out[$vehicle->id] = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
                ->where('wheel_configuration_version_id', $mapping->wheel_configuration_version_id)
                ->pluck('position_code')->mapWithKeys(fn ($c) => [Str::upper($c) => $c])->all();
        }

        return $out;
    }

    /** @return array<string, array<string, string>> vehicle id → position → serial of the active rim */
    private function occupiedRimPositions(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        $out = [];
        DB::table('component_installations as ci')
            ->join('component_assets as ca', 'ca.id', '=', 'ci.component_asset_id')
            ->join('products as p', 'p.id', '=', 'ca.product_id')
            ->whereIn('ci.vehicle_id', $vehicleIds)->whereNull('ci.removed_at')->where('p.product_type', 'RIM')
            ->get(['ci.vehicle_id', 'ci.position_location', 'ca.serial_number', 'ca.asset_number'])
            ->each(function ($r) use (&$out) {
                $out[$r->vehicle_id][$r->position_location] = $r->serial_number ?? $r->asset_number;
            });

        return $out;
    }

    /** @return array<string, true> lower(serial) of the tenant's registered component assets */
    private function existingSerials(array $serials): array
    {
        $keys = array_values(array_unique(array_map(fn ($s) => Str::lower(trim($s)), $serials)));
        $existing = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereNull('deleted_at')
                ->whereIn(DB::raw('lower(trim(serial_number))'), $chunk)->pluck('serial_number')
                ->each(function ($s) use (&$existing) {
                    $existing[Str::lower(trim($s))] = true;
                });
        }

        return $existing;
    }
}
