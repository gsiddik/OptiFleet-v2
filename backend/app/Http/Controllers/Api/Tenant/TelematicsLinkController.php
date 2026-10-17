<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Integration\Optinexus\TelematicsReviewPending;
use App\Domain\Integration\Optinexus\TelematicsReviewRejected;
use App\Domain\Integration\Optinexus\TelematicsStatus;
use App\Domain\Integration\Optinexus\VehicleOdometerService;
use App\Http\Controllers\Controller;
use App\Http\Support\OdometerErrors;
use App\Http\Support\OdometerReadingPresenter;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telematics (OptiRadar) of the tenant: the state of the feed, the linked devices, their manual calibration, the review of readings
 * that were quarantined, and the switch that activates the feed for the tenant. Branch data scope applies exactly as for vehicles.
 * Nothing here is needed to keep an odometer: manual entry (VehicleOdometerController) works without any of it.
 */
class TelematicsLinkController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DataScopeService $scope,
        private readonly VehicleOdometerService $odometer,
        private readonly TelematicsStatus $status,
        private readonly AuditService $audit,
    ) {}

    /** State of the feed, device counts and what needs a person (from stored facts; OptiNexus is not called). */
    public function status(): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($this->context->tenantId());

        return $this->ok($this->status->build($tenant, $this->scopedLinks()))->header('Cache-Control', 'no-store');
    }

    /**
     * Switches the feed on or off for this tenant. Switching on only allows the scheduled sync to ask OptiNexus; whether the tenant is
     * subscribed is OptiNexus' answer and shows in the status. Switching off stops requests; stored readings and the cursor stay.
     */
    public function activation(Request $request): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $tenant = Tenant::query()->findOrFail($this->context->tenantId());

        if (! config('optinexus.enabled')) {
            return OdometerErrors::response('TELEMATICS_UNAVAILABLE', 409, 'active');
        }
        if (! $tenant->optinexus_tenant_id) {
            return OdometerErrors::response('TELEMATICS_NOT_LINKED', 409, 'active');
        }

        $active = (bool) $data['active'];
        if ($active !== ($tenant->telematics_activated_at !== null)) {
            $tenant->forceFill([
                'telematics_activated_at' => $active ? now() : null,
                'telematics_activated_by' => $active ? $this->context->user()?->id : null,
            ])->save();
            $this->audit->log('Tenant', $tenant->id, $active ? 'telematics.activated' : 'telematics.deactivated', null, ['by' => $this->context->user()?->id], $tenant->id);
        }

        return $this->ok($this->status->build($tenant->refresh(), $this->scopedLinks()))->header('Cache-Control', 'no-store');
    }

    public function index(Request $request)
    {
        $query = $this->scopedLinks()->with('vehicle:id,registration_number,branch_id,current_odometer');

        if ($request->boolean('needs_calibration')) {
            $query->needsCalibration();
        }
        if ($request->boolean('review')) {
            $query->where('status', VehicleTelematicsLink::STATUS_REVIEW);
        }

        return $this->paginated($query->orderBy('created_at')->paginate($request->integer('per_page', 25)), fn (VehicleTelematicsLink $link) => $this->present($link));
    }

    public function calibrate(Request $request, VehicleTelematicsLink $link)
    {
        $this->authorizeLink($link);

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
        } catch (TelematicsReviewPending) {
            return OdometerErrors::response(TelematicsReviewPending::CODE, 409, 'odometer_offset_km');
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $this->ok($this->present($link->load('vehicle:id,registration_number,branch_id,current_odometer')));
    }

    /**
     * A person's decision on a device whose reading was quarantined: dismiss it, accept it, or rebase the device to the odometer
     * recorded here (replaced or reset device). A note is required; lowering the odometer needs a real reason and is a correction.
     */
    public function review(Request $request, VehicleTelematicsLink $link): JsonResponse
    {
        $this->authorizeLink($link);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:dismiss,accept,rebase'],
            'note' => ['required', 'string', 'max:500'],
            'actual_odometer_km' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        try {
            $link = $this->odometer->resolveReview($link, $data['decision'], $data['note'], isset($data['actual_odometer_km']) ? (string) $data['actual_odometer_km'] : null, $this->context->user()?->id);
        } catch (TelematicsReviewRejected $e) {
            return OdometerErrors::fromRejected($e, $e->errorCode === TelematicsReviewRejected::NOTE_REQUIRED || $e->errorCode === TelematicsReviewRejected::REASON_REQUIRED ? 'note' : 'actual_odometer_km');
        }

        return $this->ok($this->present($link->load('vehicle:id,registration_number,branch_id,current_odometer')));
    }

    /** @return Builder<VehicleTelematicsLink> */
    private function scopedLinks(): Builder
    {
        return VehicleTelematicsLink::query()
            ->where('tenant_id', $this->context->tenantId())
            ->whereHas('vehicle', fn ($q) => $this->scope->applyBranchScope($q, $this->context->user(), $this->context->tenantId(), 'branch_id'));
    }

    private function authorizeLink(VehicleTelematicsLink $link): void
    {
        abort_unless($link->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $link->vehicle->branch_id),
            403,
            'This vehicle is outside your assigned data scope.'
        );
    }

    private function reportsOdometer(VehicleTelematicsLink $link): bool
    {
        return VehicleOdometerReading::query()
            ->where(['tenant_id' => $link->tenant_id, 'vehicle_id' => $link->vehicle_id, 'source' => $link->source, 'device_ref' => $link->device_ref, 'odometer_kind' => VehicleOdometerReading::KIND_DEVICE])
            ->exists();
    }

    private function present(VehicleTelematicsLink $link): array
    {
        $now = now();
        $latest = VehicleOdometerReading::query()
            ->where(['tenant_id' => $link->tenant_id, 'vehicle_id' => $link->vehicle_id, 'source' => $link->source, 'device_ref' => $link->device_ref])
            ->orderByDesc('recorded_at')->first();
        $review = $link->underReview() && $link->review_reading_id ? VehicleOdometerReading::query()->find($link->review_reading_id) : null;
        $age = TelematicsStatus::readingAgeSeconds($link->last_measured_at, $now);

        return [
            'id' => $link->id,
            'vehicle_id' => $link->vehicle_id,
            'registration_number' => $link->vehicle->registration_number,
            'current_odometer' => $link->vehicle->current_odometer,
            'device_ref' => $link->device_ref,
            'odometer_offset_km' => $link->odometer_offset_km,
            'rebase_offset_km' => $link->rebase_offset_km,
            'calibrated' => $link->isCalibrated(),
            // GPS distance is not an odometer: it needs calibration unless the device reports a real one.
            'needs_calibration' => ! $link->isCalibrated() && ! $this->reportsOdometer($link),
            'calibrated_at' => $link->calibrated_at,
            'status' => $link->status,
            'has_readings' => $link->last_measured_at !== null,
            'last_measured_at' => $link->last_measured_at?->toIso8601String(),
            'last_received_at' => $link->last_received_at?->toIso8601String(),
            'age_seconds' => $age,
            'stale' => TelematicsStatus::readingIsStale($age, (int) config('optinexus.telematics.reading_stale_hours')),
            'review' => $link->underReview() ? [
                'reason' => $link->review_reason,
                'opened_at' => $link->review_opened_at?->toIso8601String(),
                'reading' => $review ? OdometerReadingPresenter::present($review, false) : null,
            ] : null,
            'latest_reading' => $latest ? [
                'kind' => $latest->odometer_kind,
                'reported_km' => $latest->reported_km,
                'effective_km' => $latest->effective_km,
                'applied' => $latest->applied,
                'unit' => $latest->unit ?? 'km',
                'disposition' => $latest->disposition,
                'quality' => $latest->quality(),
                'anomaly_code' => $latest->anomaly_code,
                'measured_at' => $latest->recorded_at?->toIso8601String(),
                'received_at' => $latest->received_at?->toIso8601String(),
                'recorded_at' => $latest->recorded_at,
            ] : null,
        ];
    }
}
