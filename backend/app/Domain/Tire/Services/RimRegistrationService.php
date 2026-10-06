<?php

namespace App\Domain\Tire\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\ComponentAsset\Services\ComponentAssetRegisterService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use App\Domain\Vehicle\Models\Vehicle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registers one physical, serial-numbered rim (a Component Asset of a Rim Product):
 *
 *   New Stock  serial + warehouse → IN_STOCK in that warehouse (an IN_STOCK component asset always has a
 *              warehouse — component_assets_location_check);
 *   Installed  serial + vehicle + wheel position → the asset is created and installed at once
 *              (an initial / last-known registration of a rim that is already on the vehicle — never a
 *              warehouse issue: no stock, movement or receipt is created).
 *
 * The single registration path for the Register Rim form, the Excel import and the seeders.
 *
 * Rules: the product is a live Rim Product; the serial is unique per tenant among component assets
 * (case and surrounding spaces ignored; the partial unique index is the final guard); the vehicle is the
 * tenant's, inside the user's branch scope and has an active Wheels Configuration; the position is one of
 * that configuration version's positions and has no active rim. A tire on the same position is not a
 * conflict — tires and rims are separate records. The vehicle row lock serialises concurrent fitments.
 */
class RimRegistrationService
{
    public function __construct(
        private readonly ComponentAssetRegisterService $register,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * @param  array{serial_number: string, warehouse_id?: ?string, vehicle_id?: ?string, position_code?: ?string, purchase_date?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function register(string $tenantId, Product $product, array $data, ?User $user): ComponentAsset
    {
        $this->assertRimProduct($product, $tenantId);
        $serial = $this->serial($data['serial_number'] ?? null);
        $vehicleId = $data['vehicle_id'] ?? null;

        return DB::transaction(function () use ($tenantId, $product, $data, $serial, $vehicleId, $user) {
            // Serialises registrations of the same serial (case-insensitive) within the tenant.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['rim-serial:'.$tenantId.':'.Str::lower($serial)]);
            if ($this->serialExists($tenantId, $serial)) {
                throw ValidationException::withMessages(['serial_number' => Messages::localized('rim.errors.serialExists', ['serial' => $serial])]);
            }

            $vehicle = null;
            $position = null;
            $warehouseId = null;
            if ($vehicleId) {
                [$vehicle, $position] = $this->installTarget($tenantId, $vehicleId, $data['position_code'] ?? null, $user);
            } else {
                $warehouseId = $this->warehouse($tenantId, $data['warehouse_id'] ?? null, $user);
            }

            $groups = DB::table('product_component_groups')->where('product_id', $product->id)->pluck('component_group_id');
            $asset = ComponentAsset::query()->create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'component_group_id' => $groups->count() === 1 ? $groups->first() : null,
                'serial_number' => $serial,
                'asset_number' => $this->register->nextAssetNumber($tenantId),
                'purchase_date' => $data['purchase_date'] ?? null,
                // Installed: created on the vehicle at once (it never was warehouse stock).
                'current_status' => $vehicle ? 'INSTALLED' : 'IN_STOCK',
                'current_vehicle_id' => $vehicle?->id,
                'current_warehouse_id' => $warehouseId,
            ]);

            if ($vehicle) {
                // The same installation record the component lifecycle uses (remove / replace / history);
                // the partial unique index keeps one active installation per asset.
                ComponentInstallation::query()->create([
                    'tenant_id' => $tenantId,
                    'component_asset_id' => $asset->id,
                    'vehicle_id' => $vehicle->id,
                    'position_location' => $position,
                    'installation_odometer' => $vehicle->current_odometer,
                    'installed_at' => now(),
                    'performed_by' => $user?->id,
                ]);
            }

            return $asset->fresh();
        });
    }

    /** The rim-occupancy of every position of a vehicle's active wheels configuration (for the form). */
    public function positions(Vehicle $vehicle): array
    {
        $mapping = $vehicle->activeWheelConfigurationMapping()->withoutGlobalScopes()->first();
        if (! $mapping) {
            return ['has_configuration' => false, 'positions' => []];
        }
        $rims = $this->activeRims($vehicle->id)->keyBy('position_location');

        return [
            'has_configuration' => true,
            'positions' => WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
                ->where('wheel_configuration_version_id', $mapping->wheel_configuration_version_id)
                ->orderBy('position_code')->pluck('position_code')
                ->map(fn ($code) => ['position_code' => $code, 'rim_serial_number' => $rims->get($code)?->serial_number])
                ->values()->all(),
        ];
    }

    public function assertRimProduct(Product $product, string $tenantId): void
    {
        if ($product->product_type !== 'RIM' || ($product->tenant_id !== null && $product->tenant_id !== $tenantId)) {
            throw ValidationException::withMessages(['product_id' => Messages::localized('rim.errors.productNotRim')]);
        }
        if ($product->trashed()) {
            throw ValidationException::withMessages(['product_id' => Messages::localized('rim.errors.productDeleted')]);
        }
    }

    public function serialExists(string $tenantId, string $serial): bool
    {
        return ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereRaw('lower(trim(serial_number)) = ?', [Str::lower(trim($serial))])->exists();
    }

    /**
     * Vehicle + position checks under the vehicle row lock.
     *
     * @return array{0: Vehicle, 1: string}
     */
    private function installTarget(string $tenantId, string $vehicleId, ?string $positionCode, ?User $user): array
    {
        $vehicle = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($vehicleId)->lockForUpdate()->first();
        if (! $vehicle) {
            throw ValidationException::withMessages(['vehicle_id' => Messages::localized('rim.errors.vehicleNotFound', ['vehicle' => $vehicleId])]);
        }
        $label = $vehicle->registration_number;
        if ($user && ! $this->scope->canAccessBranch($user, $tenantId, (string) $vehicle->branch_id)) {
            throw ValidationException::withMessages(['vehicle_id' => Messages::localized('rim.errors.vehicleOutOfScope', ['vehicle' => $label])]);
        }
        $positionCode = trim((string) $positionCode);
        if ($positionCode === '') {
            throw ValidationException::withMessages(['position_code' => Messages::localized('rim.errors.positionRequired')]);
        }
        $mapping = $vehicle->activeWheelConfigurationMapping()->withoutGlobalScopes()->first();
        if (! $mapping) {
            throw ValidationException::withMessages(['vehicle_id' => Messages::localized('rim.errors.noWheelConfiguration', ['vehicle' => $label])]);
        }
        $position = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
            ->where('wheel_configuration_version_id', $mapping->wheel_configuration_version_id)
            ->whereRaw('upper(position_code) = ?', [Str::upper($positionCode)])->value('position_code');
        if (! $position) {
            throw ValidationException::withMessages(['position_code' => Messages::localized('rim.errors.invalidPosition', ['position' => $positionCode, 'vehicle' => $label])]);
        }
        $occupant = $this->activeRims($vehicle->id)->firstWhere('position_location', $position);
        if ($occupant) {
            throw ValidationException::withMessages(['position_code' => Messages::localized('rim.errors.positionOccupied', ['position' => $position, 'vehicle' => $label, 'serial' => $occupant->serial_number ?? $occupant->asset_number])]);
        }

        return [$vehicle, $position];
    }

    /** Active rim installations of a vehicle (position_location + serial). */
    private function activeRims(string $vehicleId)
    {
        return ComponentInstallation::query()->withoutGlobalScopes()
            ->join('component_assets as ca', 'ca.id', '=', 'component_installations.component_asset_id')
            ->join('products as p', 'p.id', '=', 'ca.product_id')
            ->where('component_installations.vehicle_id', $vehicleId)->whereNull('component_installations.removed_at')
            ->where('p.product_type', 'RIM')
            ->get(['component_installations.position_location', 'ca.serial_number', 'ca.asset_number']);
    }

    /** A warehouse of the tenant inside the user's warehouse scope (New Stock location). */
    private function warehouse(string $tenantId, ?string $warehouseId, ?User $user): string
    {
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => Messages::localized('rim.errors.warehouseRequired')]);
        }
        $exists = DB::table('warehouses')->where('tenant_id', $tenantId)->where('id', $warehouseId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['warehouse_id' => Messages::localized('rim.errors.warehouseNotFound', ['warehouse' => $warehouseId])]);
        }
        $allowed = $user ? $this->scope->allowedWarehouseIds($user, $tenantId) : null;
        if ($allowed !== null && ! in_array($warehouseId, $allowed, true)) {
            throw ValidationException::withMessages(['warehouse_id' => Messages::localized('rim.errors.warehouseOutOfScope')]);
        }

        return $warehouseId;
    }

    private function serial(mixed $value): string
    {
        $serial = is_scalar($value) ? trim((string) $value) : '';
        if ($serial === '') {
            throw ValidationException::withMessages(['serial_number' => Messages::localized('rim.errors.serialRequired')]);
        }
        if (mb_strlen($serial) > 100) {
            throw ValidationException::withMessages(['serial_number' => Messages::localized('rim.errors.serialTooLong')]);
        }

        return $serial;
    }
}
