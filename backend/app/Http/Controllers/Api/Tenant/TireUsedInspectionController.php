<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Inspection\UsedTireInspectionService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Models\TireUsedInspectionDamage;
use App\Domain\Tire\Models\TireUsedInspectionEvidence;
use App\Domain\Tire\Models\TireUsedInspectionMeasurement;
use App\Domain\Tire\Services\TireAgeService;
use App\Domain\Tire\Services\TireInventoryService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Used Tire Management inspection of REMOVED / HOLD tires. Inspecting (evaluate, submit, cancel,
 * evidence) needs tire.inspect; applying the disposition needs tire_used_inspection.approve.
 */
class TireUsedInspectionController extends Controller
{
    private const CODES = [
        'identity_status' => ['COMPLETE', 'PARTIALLY_UNKNOWN', 'CANNOT_VERIFY'],
        'internal_inspected' => ['YES', 'NOT_YET'],
        'wear_pattern' => ['EVEN', 'ONE_SIDED', 'CENTER', 'BOTH_SIDES', 'CUPPING_SCALLOPING', 'FLAT_SPOT', 'NOT_INSPECTED'],
        'bulge_separation' => ['NONE', 'PRESENT', 'SUSPECTED', 'NOT_INSPECTED'],
        'cord_exposure' => ['NONE', 'PRESENT', 'SUSPECTED', 'NOT_INSPECTED'],
        'sidewall_condition' => ['NORMAL', 'SURFACE_ABRASION', 'SURFACE_CRACKING', 'DEEP_CUT_CRACK', 'NOT_INSPECTED'],
        'bead_condition' => ['NORMAL', 'MINOR_ABRASION', 'TORN', 'DEFORMED', 'BEAD_WIRE_DAMAGED', 'NOT_INSPECTED'],
        'inner_liner_condition' => ['NORMAL', 'LOCAL_DAMAGE', 'CRACKED_DELAMINATED', 'WRINKLED_HEAT_DAMAGE', 'CORD_EXPOSED', 'NOT_INSPECTED'],
        'run_flat_overheat' => ['NO', 'HISTORY_NO_SIGN', 'PHYSICAL_SIGN', 'UNKNOWN'],
        'leak_foreign_object' => ['NO', 'YES', 'NOT_TESTED'],
        'previous_repair' => ['NONE', 'MEETS_STANDARD', 'QUESTIONABLE', 'DOES_NOT_MEET', 'NOT_INSPECTED'],
        'age_chemical' => ['NONE', 'SUSPECTED', 'DEGRADED', 'NOT_INSPECTED'],
        'casing_compliance' => ['MEETS', 'DOES_NOT_MEET', 'CANNOT_CONFIRM'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly UsedTireInspectionService $inspections,
        private readonly TireInventoryService $inventory,
    ) {}

    public function context(Request $request, Tire $tire)
    {
        $this->authorizeTire($tire);

        return $this->ok($this->inspections->context($tire, $this->context->user(), $request->string('application')->trim()->value() ?: null));
    }

    public function evaluate(Request $request, Tire $tire)
    {
        $this->authorizeTire($tire);

        return $this->ok($this->inspections->evaluate($tire, $this->validated($request, false), $this->context->user()));
    }

    public function store(Request $request, Tire $tire)
    {
        $this->authorizeTire($tire);
        $inspection = $this->inspections->submit($tire, $this->validated($request, true), $this->context->user());

        return $this->ok($this->inspections->present($inspection), 201);
    }

    public function show(TireUsedInspection $tireUsedInspection)
    {
        $this->authorizeTire($tireUsedInspection->tire);

        return $this->ok($this->inspections->present($tireUsedInspection));
    }

    public function approve(Request $request, TireUsedInspection $tireUsedInspection)
    {
        $this->authorizeTire($tireUsedInspection->tire);
        $tenantId = $this->context->tenantId();
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['warehouse_id']) && ! app(DataScopeService::class)->canAccessWarehouse($this->context->user(), $tenantId, $data['warehouse_id'])) {
            throw ValidationException::withMessages(['warehouse_id' => 'This warehouse is outside your data scope.']);
        }

        return $this->ok($this->inspections->present($this->inspections->approve($tireUsedInspection, $data, $this->context->user())));
    }

    /**
     * Tire Inspection → Tire Identity: fill in a missing Manufacture Date Code. It is saved on the
     * physical tire (not only the inspection snapshot) and the response carries the refreshed facts
     * (Tire Age is computed by TireAgeService). A code that is already set is not changed here.
     */
    public function updateManufactureDateCode(Request $request, Tire $tire)
    {
        $this->authorizeTire($tire);
        $data = $request->validate([
            'manufacture_date_code' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
                if (! app(TireAgeService::class)->isValidCode((string) $value)) {
                    $fail(TireAgeService::FORMAT_MESSAGE);
                }
            }],
        ]);

        return $this->ok($this->inspections->setManufactureDateCode($tire, trim($data['manufacture_date_code']), $this->context->user()));
    }

    public function cancel(TireUsedInspection $tireUsedInspection)
    {
        $this->authorizeTire($tireUsedInspection->tire);

        return $this->ok($this->inspections->present($this->inspections->cancel($tireUsedInspection, $this->context->user())));
    }

    public function storeEvidence(Request $request, TireUsedInspection $tireUsedInspection)
    {
        $this->authorizeTire($tireUsedInspection->tire);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:3072'],
            'kind' => ['required', Rule::in(TireUsedInspectionEvidence::KINDS)],
            'damage_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $evidence = $this->inspections->addEvidence($tireUsedInspection, $request->file('file'), $data['kind'], $data['damage_id'] ?? null, $data['notes'] ?? null, $this->context->user());

        return $this->ok($evidence->only(['id', 'kind', 'tire_used_inspection_damage_id', 'original_filename', 'mime_type', 'size', 'notes']), 201);
    }

    public function showEvidence(TireUsedInspection $tireUsedInspection, string $evidence)
    {
        $this->authorizeTire($tireUsedInspection->tire);
        $file = $tireUsedInspection->evidence()->whereKey($evidence)->firstOrFail();

        return Storage::disk($file->disk)->response($file->path, $file->original_filename, ['Content-Type' => $file->mime_type]);
    }

    /** Questionnaire + measurements + damages. Submitting requires every question answered; evaluating does not. */
    private function validated(Request $request, bool $submit): array
    {
        $answer = fn (string $key) => [$submit ? 'required' : 'nullable', Rule::in(self::CODES[$key])];
        $rules = collect(self::CODES)->mapWithKeys(fn ($v, $key) => [$key => $answer($key)])->all() + [
            'repair_eligibility' => ['nullable', Rule::in(['YES', 'NO', 'SPECIALIST_REQUIRED'])],
            'specialist_result' => ['nullable', Rule::in(['NOT_REQUESTED', 'PENDING', 'ACCEPTED', 'REJECTED'])],
            'application' => ['nullable', 'string', 'max:50'],
            'd_new_mm' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'measurements' => [$submit ? 'required' : 'nullable', 'array', 'max:9'],
            'measurements.*.zone' => ['required', 'integer', 'between:1,3'],
            'measurements.*.groove' => ['required', Rule::in(TireUsedInspectionMeasurement::GROOVES)],
            'measurements.*.depth_mm' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'damages' => ['nullable', 'array', 'max:20'],
            'damages.*.location' => ['required', Rule::in(TireUsedInspectionDamage::LOCATIONS)],
            'damages.*.damage_type' => ['required', Rule::in(TireUsedInspectionDamage::TYPES)],
            'damages.*.diameter_mm' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'damages.*.length_mm' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'damages.*.width_mm' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'damages.*.depth_mm' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'damages.*.reaches_reinforcement' => ['required', Rule::in(['NO', 'YES', 'UNKNOWN'])],
            'damages.*.overlaps_previous_repair' => ['required', Rule::in(['NO', 'YES', 'UNKNOWN'])],
            'damages.*.notes' => ['nullable', 'string', 'max:500'],
        ];
        $data = $request->validate($rules);
        $points = collect($data['measurements'] ?? [])->map(fn ($m) => $m['zone'].'|'.$m['groove']);
        if ($points->count() !== $points->unique()->count()) {
            throw ValidationException::withMessages(['measurements' => 'Each zone / groove point can be measured once.']);
        }

        return $data;
    }

    /** Tenant (route binding) + the tire data scope used everywhere else. */
    private function authorizeTire(?Tire $tire): void
    {
        abort_unless($tire && $tire->tenant_id === $this->context->tenantId(), 404);
        $visible = $this->inventory->scopeToUser(DB::table('tires')->where('tires.id', $tire->id), $tire->tenant_id, $this->context->user())->exists();
        abort_unless($visible, 404);
    }
}
