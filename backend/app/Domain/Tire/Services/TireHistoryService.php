<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\Tire;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tire History of one physical tire, read from the actual records only (never synthesized from its
 * current state): installations (with their removal), rotations and inspections, each oldest first.
 * Each section is returned even when empty — the client shows only the sections that have data.
 * Times are given in the tenant's time zone.
 */
class TireHistoryService
{
    public function __construct(private readonly VehicleTireRegistrationService $registrations) {}

    public function history(Tire $tire): array
    {
        $timezone = $this->registrations->timezone($tire->tenant_id);
        $local = fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->setTimezone($timezone)->format('Y-m-d H:i');

        $installations = DB::table('tire_installations as i')
            ->leftJoin('vehicles as v', 'v.id', '=', 'i.vehicle_id')
            ->leftJoin('tire_removals as r', 'r.tire_installation_id', '=', 'i.id')
            ->where('i.tire_id', $tire->id)
            ->orderBy('i.installed_at')->orderBy('i.created_at')
            ->get(['i.id', 'i.wheel_position', 'i.installed_at', 'i.installation_odometer', 'i.removed_at', 'v.id as vehicle_id', 'v.registration_number',
                'r.removal_reason', 'r.removal_odometer', 'r.disposition'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'vehicle' => $r->vehicle_id ? ['id' => $r->vehicle_id, 'registration_number' => $r->registration_number] : null,
                'position_code' => $r->wheel_position,
                'installed_at' => $local($r->installed_at),
                'installation_odometer' => $r->installation_odometer,
                'removed_at' => $local($r->removed_at),
                'removal_odometer' => $r->removal_odometer,
                'removal_reason' => $r->removal_reason,
                'removal_disposition' => $r->disposition,
            ])->values()->all();

        $rotations = DB::table('tire_rotations as r')
            ->leftJoin('vehicles as v', 'v.id', '=', 'r.vehicle_id')
            ->where('r.tire_id', $tire->id)
            ->orderBy('r.occurred_at')->orderBy('r.created_at')
            ->get(['r.id', 'r.from_position', 'r.to_position', 'r.odometer', 'r.occurred_at', 'v.registration_number'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'registration_number' => $r->registration_number,
                'from_position' => $r->from_position,
                'to_position' => $r->to_position,
                'odometer' => $r->odometer,
                'occurred_at' => $local($r->occurred_at),
            ])->values()->all();

        $inspections = DB::table('tire_inspections as t')
            ->leftJoin('users as u', 'u.id', '=', 't.inspected_by')
            ->where('t.tire_id', $tire->id)
            ->orderBy('t.inspected_at')->orderBy('t.created_at')
            ->get(['t.id', 't.inspected_at', 't.tread_depth_mm', 't.pressure_psi', 't.condition', 't.damage', 't.recommendation', 'u.name as inspector'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'inspected_at' => $local($r->inspected_at),
                'tread_depth_mm' => $r->tread_depth_mm,
                'pressure_psi' => $r->pressure_psi,
                'condition' => $r->condition,
                'damage' => $r->damage,
                'recommendation' => $r->recommendation,
                'inspector' => $r->inspector,
            ])->values()->all();

        return [
            'tire' => ['id' => $tire->id, 'serial_number' => $tire->serial_number, 'current_status' => $tire->current_status],
            'installations' => $installations,
            'rotations' => $rotations,
            'inspections' => $inspections,
        ];
    }
}
