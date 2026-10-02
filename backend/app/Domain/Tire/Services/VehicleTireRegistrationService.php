<?php

namespace App\Domain\Tire\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * Tires on a vehicle's wheel configuration positions (Vehicle Detail → Wheels Configuration).
 * Dates are shown in the tenant's timezone (stored in UTC).
 */
class VehicleTireRegistrationService
{
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
