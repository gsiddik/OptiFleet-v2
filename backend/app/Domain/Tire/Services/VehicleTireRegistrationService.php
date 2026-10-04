<?php

namespace App\Domain\Tire\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use App\Domain\Tire\Support\TireStatus;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tires on a vehicle's wheel configuration positions (Vehicle Detail → Wheels Configuration),
 * including the initial / last-known registration of a tire that is already on the vehicle.
 *
 * Initial registration is NOT a warehouse issue: it never creates or touches stock, stock
 * movements, reservations, transfers or receipts. It only records the physical tire (Product of
 * Item Type Tire + serial number) and its baseline installation:
 *
 *   Validate Position → Validate Product is Tire → Validate Serial → Create/Reuse Tire
 *   → Create Installation (INITIAL_REGISTRATION) → Mark Installed → Commit
 *
 * Dates are entered and shown in the tenant's timezone and stored in UTC.
 */
class VehicleTireRegistrationService
{
    public function __construct(
        private readonly TireRegistrationService $tireRegistration,
        private readonly TireService $tires,
    ) {}

    /**
     * @param  array{position_code: string, installed_date: string, installed_time: string, installation_km: string, product_id: string, serial_number: string, tread_depth_mm?: ?string}  $data
     *
     * @throws ValidationException|TireException
     */
    public function register(Vehicle $vehicle, array $data, string $userId): TireInstallation
    {
        $serial = trim($data['serial_number']);
        $timezone = $this->timezone($vehicle->tenant_id);
        $installedAt = Carbon::createFromFormat('Y-m-d H:i', "{$data['installed_date']} {$data['installed_time']}", $timezone)->utc();
        if ($installedAt->isFuture()) {
            throw ValidationException::withMessages(['installed_date' => 'The installation date and time cannot be in the future.']);
        }

        return DB::transaction(function () use ($vehicle, $data, $serial, $installedAt, $userId) {
            // The vehicle row lock serialises this with Vehicle Mapping changes and other installs.
            $locked = Vehicle::query()->withoutGlobalScopes()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            // Validate Position: must belong to the vehicle's mapped configuration version and be free.
            $mapping = $locked->activeWheelConfigurationMapping()->withoutGlobalScopes()->first();
            if (! $mapping) {
                throw ValidationException::withMessages(['position_code' => 'Assign a wheels configuration to this vehicle before registering tires.']);
            }
            $isPosition = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
                ->where('wheel_configuration_version_id', $mapping->wheel_configuration_version_id)
                ->where('position_code', $data['position_code'])->exists();
            if (! $isPosition) {
                throw ValidationException::withMessages(['position_code' => "{$data['position_code']} is not a position of this vehicle's wheels configuration."]);
            }
            $occupied = TireInstallation::query()->withoutGlobalScopes()->where('vehicle_id', $locked->id)
                ->where('wheel_position', $data['position_code'])->whereNull('removed_at')->with('tire:id,serial_number')->first();
            if ($occupied) {
                throw ValidationException::withMessages(['position_code' => "Position {$data['position_code']} already has tire {$occupied->tire?->serial_number} installed."]);
            }

            // Validate Product is Tire (tenant or platform product).
            $product = Product::query()->withoutGlobalScopes()->whereKey($data['product_id'])
                ->where(fn ($q) => $q->where('tenant_id', $locked->tenant_id)->orWhereNull('tenant_id'))->first();
            if (! $product || $product->product_type !== 'TIRE') {
                throw ValidationException::withMessages(['product_id' => 'Select a product whose Item Type is Tire.']);
            }

            // Validate Serial → Create/Reuse Tire (never a duplicate serial).
            $tire = $this->resolveTire($locked, $product, $serial);

            // Create Installation → Mark Installed (TireService records the baseline tread depth).
            $installation = $this->tires->install(
                $tire, $locked, $data['position_code'],
                (float) $data['installation_km'],
                null, $userId, $installedAt, 'KNOWN',
                isset($data['tread_depth_mm']) && $data['tread_depth_mm'] !== '' ? (float) $data['tread_depth_mm'] : null,
            );
            $installation->update(['installation_source' => 'INITIAL_REGISTRATION']);

            return $installation->fresh();
        });
    }

    /** Products of Item Type Tire for the registration dropdown (server-side search). */
    public function tireProducts(string $tenantId, ?string $search): array
    {
        return Product::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('product_type', 'TIRE')->whereNull('deleted_at')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")))
            ->orderBy('name')->limit(25)->get(['id', 'name', 'sku'])->all();
    }

    private function resolveTire(Vehicle $vehicle, Product $product, string $serial): Tire
    {
        $existing = Tire::query()->withoutGlobalScopes()->where('tenant_id', $vehicle->tenant_id)
            ->whereRaw('lower(trim(serial_number)) = ?', [Str::lower($serial)])->whereNull('deleted_at')
            ->with('currentVehicle:id,registration_number')->lockForUpdate()->first();
        if (! $existing) {
            return $this->tireRegistration->register($vehicle->tenant_id, ['product_id' => $product->id, 'serial_number' => $serial]);
        }

        if ($existing->current_status === 'INSTALLED') {
            throw ValidationException::withMessages(['serial_number' => "Tire {$existing->serial_number} is already installed on {$existing->currentVehicle?->registration_number} at {$existing->current_position}."]);
        }
        if ($existing->product_id !== $product->id) {
            throw ValidationException::withMessages(['serial_number' => "Serial number {$existing->serial_number} is already registered for another tire product."]);
        }
        if (! in_array($existing->current_status, TireStatus::AVAILABLE_FOR_INSTALLATION, true)) {
            throw ValidationException::withMessages(['serial_number' => "Tire {$existing->serial_number} is {$existing->current_status} and cannot be registered on a vehicle."]);
        }
        if ($existing->current_warehouse_id !== null) {
            // Taking it from a warehouse would bypass warehouse stock; that is the warehouse issue flow.
            throw ValidationException::withMessages(['serial_number' => "Tire {$existing->serial_number} is in warehouse stock; install it through the warehouse issue flow."]);
        }

        return $existing;
    }

    /** @return list<array<string, mixed>> one entry per active installation */
    public function activeInstallations(Vehicle $vehicle): array
    {
        $timezone = $this->timezone($vehicle->tenant_id);
        $installations = TireInstallation::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicle->id)->whereNull('removed_at')
            ->with(['tire' => fn ($q) => $q->withoutGlobalScopes()->with('product:id,name,sku')])
            ->orderBy('wheel_position')->get();

        $treads = TireInspection::query()->withoutGlobalScopes()
            ->whereIn('tire_id', $installations->pluck('tire_id'))->whereNotNull('tread_depth_mm')
            ->orderByDesc('inspected_at')->get()->unique('tire_id')->keyBy('tire_id');

        return $installations->map(fn (TireInstallation $i) => [
            'installation_id' => $i->id,
            'position_code' => $i->wheel_position,
            'installed_at' => $i->installed_at,
            'installed_date' => $this->local($i->installed_at, $timezone, 'Y-m-d'),
            'installed_time' => $this->local($i->installed_at, $timezone, 'H:i'),
            'installation_date_source' => $i->installation_date_source,
            'installation_source' => $i->installation_source ?? null,
            'installation_odometer' => $i->installation_odometer,
            'tread_depth_mm' => $treads->get($i->tire_id)?->tread_depth_mm,
            'tire' => [
                'id' => $i->tire?->id,
                'serial_number' => $i->tire?->serial_number,
                'current_status' => $i->tire?->current_status,
                'product' => $i->tire?->product?->only(['id', 'name', 'sku']),
            ],
        ])->values()->all();
    }

    public function timezone(string $tenantId): string
    {
        return Tenant::query()->whereKey($tenantId)->value('timezone') ?: config('app.timezone');
    }

    private function local(?CarbonInterface $at, string $timezone, string $format): ?string
    {
        return $at?->copy()->setTimezone($timezone)->format($format);
    }
}
