<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Integration\Optinexus\VehicleOdometerService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Telematics (OptiRadar) links of the tenant's vehicles and their manual
 * calibration. Branch data scope applies exactly as for vehicles.
 */
class TelematicsLinkController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DataScopeService $scope,
        private readonly VehicleOdometerService $odometer,
    ) {}

    public function index(Request $request)
    {
        $query = VehicleTelematicsLink::query()->with('vehicle:id,registration_number,branch_id,current_odometer')
            ->where('tenant_id', $this->context->tenantId())
            ->whereHas('vehicle', fn ($q) => $this->scope->applyBranchScope($q, $this->context->user(), $this->context->tenantId(), 'branch_id'));

        if ($request->query('calibrated') === 'false') {
            $query->whereNull('odometer_offset_km');
        }

        return $this->paginated($query->orderBy('created_at')->paginate($request->integer('per_page', 25)), fn (VehicleTelematicsLink $link) => $this->present($link));
    }

    public function calibrate(Request $request, VehicleTelematicsLink $link)
    {
        abort_unless($link->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $link->vehicle->branch_id),
            403,
            'This vehicle is outside your assigned data scope.'
        );

        $data = $request->validate([
            'odometer_offset_km' => ['required_without:actual_odometer_km', 'nullable', 'numeric', 'between:-99999999,99999999'],
            'actual_odometer_km' => ['required_without:odometer_offset_km', 'nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        try {
            $link = $this->odometer->calibrate(
                $link,
                isset($data['odometer_offset_km']) ? (string) $data['odometer_offset_km'] : null,
                isset($data['actual_odometer_km']) ? (string) $data['actual_odometer_km'] : null,
                $this->context->user()?->id,
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $this->ok($this->present($link->load('vehicle:id,registration_number,branch_id,current_odometer')));
    }

    private function present(VehicleTelematicsLink $link): array
    {
        $latest = VehicleOdometerReading::query()
            ->where(['tenant_id' => $link->tenant_id, 'vehicle_id' => $link->vehicle_id, 'source' => $link->source, 'device_ref' => $link->device_ref])
            ->orderByDesc('recorded_at')->first();

        return [
            'id' => $link->id,
            'vehicle_id' => $link->vehicle_id,
            'registration_number' => $link->vehicle->registration_number,
            'current_odometer' => $link->vehicle->current_odometer,
            'device_ref' => $link->device_ref,
            'odometer_offset_km' => $link->odometer_offset_km,
            'calibrated' => $link->isCalibrated(),
            'calibrated_at' => $link->calibrated_at,
            'latest_reading' => $latest ? [
                'kind' => $latest->odometer_kind,
                'reported_km' => $latest->reported_km,
                'effective_km' => $latest->effective_km,
                'applied' => $latest->applied,
                'recorded_at' => $latest->recorded_at,
            ] : null,
        ];
    }
}
