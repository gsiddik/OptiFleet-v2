<?php

namespace App\Domain\Tire\Inspection;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireRuleProfile;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Models\TireUsedInspectionEvidence;
use App\Domain\Tire\Services\TireException;
use App\Domain\Tire\Services\TireInventoryService;
use App\Domain\Tire\Services\VehicleTireRegistrationService;
use App\Domain\Tire\Support\TireStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Used Tire Management → Removed → Inspect. A REMOVED / HOLD tire is inspected with the core
 * questionnaire, ≥ 6 tread points and (when problematic) damage rows; UsedTireDecisionEngine turns
 * that into REUSE / REPAIR / RETREAD / HOLD / SCRAP using the tire category's rule profile. The
 * inspection stores the profile version and the thresholds applied, so a historical result stays
 * explainable after the rules change. Submitting does not change the tire; approving applies the
 * recommendation as the tire's status (REUSE = reusable used stock in the chosen warehouse).
 */
class UsedTireInspectionService
{
    /** Product tire spec vehicle group → inspection tire category. */
    public const CATEGORY_BY_GROUP = ['CAR' => 'PASSENGER_LT', 'TRUCK_BUS' => 'TRUCK_BUS', 'OTR' => 'OTR'];

    public const CATEGORY_LABELS = ['PASSENGER_LT' => 'Passenger / Light Truck', 'TRUCK_BUS' => 'Truck / Bus', 'OTR' => 'OTR / Heavy Equipment'];

    /** Recommendation → tire status applied on approval. */
    private const STATUS_BY_DISPOSITION = [
        'REUSE' => TireStatus::REUSE, 'REPAIR' => 'REPAIR', 'RETREAD' => 'RETREAD', 'HOLD' => TireStatus::HOLD, 'SCRAP' => TireStatus::SCRAPPED,
    ];

    public function __construct(
        private readonly UsedTireDecisionEngine $engine,
        private readonly TireInventoryService $inventory,
        private readonly VehicleTireRegistrationService $registrations,
    ) {}

    // ------------------------------------------------------------------ reads

    /** Everything the inspection page shows before the questionnaire (auto-filled from master + history). */
    public function context(Tire $tire, User $user, ?string $application = null): array
    {
        $facts = $this->facts($tire, $user);
        $profile = $this->profileFor($tire->tenant_id, $facts['category'], $tire->product_id, $application);
        $open = TireUsedInspection::query()->where('tire_id', $tire->id)->where('status', TireUsedInspection::SUBMITTED)->first();

        return [
            'tire' => $facts,
            'can_inspect' => in_array($tire->current_status, TireStatus::AWAITING_INSPECTION, true) && $open === null,
            'rule_profile' => $profile?->snapshot(),
            'applications' => TireRuleProfile::query()->where('tenant_id', $tire->tenant_id)->where('status', 'ACTIVE')
                ->where('tire_category', $facts['category'])->whereNotNull('application')->orderBy('application')->distinct()->pluck('application')->all(),
            'open_inspection' => $open ? $this->present($open) : null,
            'inspections' => TireUsedInspection::query()->where('tire_id', $tire->id)->orderByDesc('inspected_at')->get()
                ->map(fn (TireUsedInspection $i) => $this->summaryRow($i))->all(),
        ];
    }

    /** The decision for the current answers, without saving anything (live recommendation). */
    public function evaluate(Tire $tire, array $input, User $user): array
    {
        $facts = $this->facts($tire, $user);
        $profile = $this->profileFor($tire->tenant_id, $facts['category'], $tire->product_id, $input['application'] ?? null);

        return $this->decide($input, $facts, $profile) + ['rule_profile' => $profile?->snapshot()];
    }

    public function present(TireUsedInspection $inspection): array
    {
        $inspection->loadMissing(['measurements', 'damages', 'evidence', 'inspector:id,name', 'approver:id,name']);
        $timezone = $this->registrations->timezone($inspection->tenant_id);
        $local = fn ($value) => $value ? CarbonImmutable::parse($value)->setTimezone($timezone)->format('Y-m-d H:i') : null;
        $recommendation = $inspection->recommendation;

        return [
            'id' => $inspection->id,
            'tire_id' => $inspection->tire_id,
            'status' => $inspection->status,
            'tire_status_before' => $inspection->tire_status_before,
            'inspected_at' => $local($inspection->inspected_at),
            'inspector' => $inspection->inspector?->name,
            'tire_category' => $inspection->tire_category,
            'tire_category_label' => self::CATEGORY_LABELS[$inspection->tire_category] ?? null,
            'application' => $inspection->application,
            'rule_profile_version' => $inspection->rule_profile_version,
            'thresholds' => $inspection->thresholds,
            'tire_snapshot' => $inspection->tire_snapshot,
            'answers' => collect(TireUsedInspection::ANSWERS)->mapWithKeys(fn ($k) => [$k => $inspection->{$k}])->all(),
            'measurements' => $inspection->measurements->map(fn ($m) => ['zone' => $m->zone, 'groove' => $m->groove, 'depth_mm' => (string) $m->depth_mm])->values()->all(),
            'damages' => $inspection->damages->map(fn ($d) => $d->only(['id', 'sequence', 'location', 'damage_type', 'diameter_mm', 'length_mm', 'width_mm', 'depth_mm', 'reaches_reinforcement', 'overlaps_previous_repair', 'notes']))->values()->all(),
            'evidence' => $inspection->evidence->map(fn ($e) => $e->only(['id', 'kind', 'tire_used_inspection_damage_id', 'original_filename', 'mime_type', 'size', 'notes']))->values()->all(),
            'd_min_mm' => $inspection->d_min_mm === null ? null : (string) $inspection->d_min_mm,
            'd_new_mm' => $inspection->d_new_mm === null ? null : (string) $inspection->d_new_mm,
            'remaining_tread_percent' => $inspection->remaining_tread_percent === null ? null : (string) $inspection->remaining_tread_percent,
            'recommendation' => $recommendation,
            'recommendation_detail' => $inspection->recommendation_detail,
            'additional_work' => $inspection->additional_work,
            'reasons' => $inspection->reasons,
            'follow_ups' => $inspection->follow_ups,
            'variables' => $inspection->variables,
            'notes' => $inspection->notes,
            'result' => $this->resultTexts($recommendation, $inspection->recommendation_detail, $inspection->additional_work, $inspection->tire_category, $inspection->leak_foreign_object === 'YES', $inspection->thresholds),
            'final_disposition' => $inspection->final_disposition,
            'return_warehouse_id' => $inspection->return_warehouse_id,
            'approved_by' => $inspection->approver?->name,
            'approved_at' => $local($inspection->approved_at),
            'approval_note' => $inspection->approval_note,
            'cancelled_at' => $local($inspection->cancelled_at),
        ];
    }

    // ----------------------------------------------------------------- writes

    public function submit(Tire $tire, array $input, User $user): TireUsedInspection
    {
        return DB::transaction(function () use ($tire, $input, $user) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if (! in_array($locked->current_status, TireStatus::AWAITING_INSPECTION, true)) {
                throw new TireException("Only a REMOVED or HOLD tire can be inspected here; this tire is {$locked->current_status}.");
            }
            if (TireUsedInspection::query()->where('tire_id', $locked->id)->where('status', TireUsedInspection::SUBMITTED)->lockForUpdate()->exists()) {
                throw new TireException('This tire already has an inspection waiting for approval — approve or cancel it first.');
            }

            $facts = $this->facts($locked, $user);
            $profile = $this->profileFor($locked->tenant_id, $facts['category'], $locked->product_id, $input['application'] ?? null);
            $decision = $this->decide($input, $facts, $profile);
            $now = now();

            // The measured tread is also an ordinary tire inspection record (Tire History, latest tread).
            $legacy = TireInspection::query()->create([
                'tenant_id' => $locked->tenant_id, 'tire_id' => $locked->id,
                'tread_depth_mm' => $decision['d_min_mm'],
                'condition' => 'Used tire inspection — recommendation '.$decision['recommendation'].($decision['recommendation_detail'] ? ' ('.str_replace('_', ' ', $decision['recommendation_detail']).')' : ''),
                'damage' => count($input['damages'] ?? []) > 0 ? count($input['damages']).' damage(s) recorded' : null,
                'recommendation' => implode(' ', $decision['reasons']),
                'inspected_by' => $user->id, 'inspected_at' => $now,
            ]);

            $inspection = TireUsedInspection::query()->create([
                'tenant_id' => $locked->tenant_id, 'tire_id' => $locked->id, 'status' => TireUsedInspection::SUBMITTED,
                'tire_status_before' => $locked->current_status, 'inspected_by' => $user->id, 'inspected_at' => $now,
                'tire_category' => $facts['category'], 'application' => $input['application'] ?? null,
                'rule_profile_id' => $profile?->id, 'rule_profile_version' => $profile?->version, 'thresholds' => $profile?->snapshot(),
                'tire_snapshot' => $facts,
                'd_min_mm' => $decision['d_min_mm'], 'd_new_mm' => ($input['d_new_mm'] ?? null) ?: null, 'remaining_tread_percent' => $decision['remaining_tread_percent'],
                'recommendation' => $decision['recommendation'], 'recommendation_detail' => $decision['recommendation_detail'],
                'additional_work' => $decision['additional_work'], 'reasons' => $decision['reasons'], 'follow_ups' => $decision['follow_ups'],
                'variables' => $decision['variables'], 'notes' => $input['notes'] ?? null, 'tire_inspection_id' => $legacy->id,
            ] + collect(TireUsedInspection::ANSWERS)->mapWithKeys(fn ($k) => [$k => $input[$k] ?? null])->all());

            foreach ($input['measurements'] ?? [] as $m) {
                $inspection->measurements()->create(['tenant_id' => $locked->tenant_id, 'zone' => $m['zone'], 'groove' => $m['groove'], 'depth_mm' => $m['depth_mm']]);
            }
            foreach (array_values($input['damages'] ?? []) as $i => $d) {
                $inspection->damages()->create(['tenant_id' => $locked->tenant_id, 'sequence' => $i + 1] + array_intersect_key($d, array_flip([
                    'location', 'damage_type', 'diameter_mm', 'length_mm', 'width_mm', 'depth_mm', 'reaches_reinforcement', 'overlaps_previous_repair', 'notes',
                ])));
            }

            return $inspection->fresh();
        });
    }

    /**
     * Applies the recommendation as the tire's status. The final disposition is the engine's
     * recommendation — it is not overridden here (re-inspect to change it). REUSE goes into the
     * chosen warehouse as reusable used stock.
     */
    public function approve(TireUsedInspection $inspection, array $data, User $user): TireUsedInspection
    {
        return DB::transaction(function () use ($inspection, $data, $user) {
            $locked = TireUsedInspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if ($locked->status !== TireUsedInspection::SUBMITTED) {
                throw new TireException("This inspection is {$locked->status}; only a submitted inspection can be approved.");
            }
            $tire = Tire::query()->lockForUpdate()->findOrFail($locked->tire_id);
            if ($tire->current_status !== $locked->tire_status_before) {
                throw new TireException("The tire changed status since it was inspected ({$locked->tire_status_before} → {$tire->current_status}); cancel this inspection and inspect it again.");
            }
            $disposition = $locked->recommendation;
            $warehouseId = $disposition === 'REUSE' ? ($data['warehouse_id'] ?? null) : null;
            if ($disposition === 'REUSE' && $warehouseId === null) {
                throw new TireException('Choose the warehouse the REUSE tire is returned to.');
            }

            $tire->update(['current_status' => self::STATUS_BY_DISPOSITION[$disposition], 'current_warehouse_id' => $warehouseId]);
            $locked->update([
                'status' => TireUsedInspection::APPROVED, 'final_disposition' => $disposition, 'return_warehouse_id' => $warehouseId,
                'approved_by' => $user->id, 'approved_at' => now(), 'approval_note' => $data['note'] ?? null,
            ]);

            return $locked->fresh();
        });
    }

    public function cancel(TireUsedInspection $inspection, User $user): TireUsedInspection
    {
        return DB::transaction(function () use ($inspection, $user) {
            $locked = TireUsedInspection::query()->lockForUpdate()->findOrFail($inspection->id);
            if ($locked->status !== TireUsedInspection::SUBMITTED) {
                throw new TireException("This inspection is {$locked->status}; only a submitted inspection can be cancelled.");
            }
            $locked->update(['status' => TireUsedInspection::CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now()]);

            return $locked->fresh();
        });
    }

    /** JPG / PNG ≤ 3 MB, type checked from the content, stored privately under a server-generated name. */
    public function addEvidence(TireUsedInspection $inspection, UploadedFile $file, string $kind, ?string $damageId, ?string $notes, User $user): TireUsedInspectionEvidence
    {
        if ($inspection->status !== TireUsedInspection::SUBMITTED) {
            throw new TireException('Evidence can only be added while the inspection waits for approval.');
        }
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png'], true) || ! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true)) {
            throw new TireException('Only JPG or PNG photos are accepted.');
        }
        if ($file->getSize() > 3 * 1024 * 1024) {
            throw new TireException('The photo exceeds the 3 MB maximum size.');
        }
        if ($damageId !== null && ! $inspection->damages()->whereKey($damageId)->exists()) {
            throw new TireException('The damage does not belong to this inspection.');
        }
        $extension = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $path = $file->storeAs("tire-inspection-evidence/{$inspection->tenant_id}", Str::uuid().'.'.$extension, ['disk' => 'local']);

        return $inspection->evidence()->create([
            'tenant_id' => $inspection->tenant_id, 'tire_used_inspection_damage_id' => $damageId, 'kind' => $kind,
            'disk' => 'local', 'path' => $path,
            'original_filename' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
            'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'notes' => $notes, 'uploaded_by' => $user->id,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** The most specific ACTIVE profile: product + application, product, application, category. */
    public function profileFor(string $tenantId, ?string $category, ?string $productId, ?string $application): ?TireRuleProfile
    {
        if ($category === null) {
            return null;
        }
        $candidates = TireRuleProfile::query()->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->where('tire_category', $category)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $productId))->get();
        $app = $application !== null && $application !== '' ? Str::lower($application) : null;

        return $candidates
            ->filter(fn (TireRuleProfile $p) => $p->application === null || ($app !== null && Str::lower($p->application) === $app))
            ->sortByDesc(fn (TireRuleProfile $p) => ($p->product_id !== null ? 2 : 0) + ($p->application !== null ? 1 : 0))
            ->first();
    }

    private function decide(array $input, array $facts, ?TireRuleProfile $profile): array
    {
        $answers = collect(TireUsedInspection::ANSWERS)->mapWithKeys(fn ($k) => [$k => $input[$k] ?? null])->all();
        $dNew = ($input['d_new_mm'] ?? null) ?: $facts['d_new_default_mm'];

        return $this->engine->evaluate($answers, array_values($input['measurements'] ?? []), array_values($input['damages'] ?? []), $profile?->snapshot(),
            ['category' => $facts['category'], 'age_months' => $facts['age_months'], 'retread_count' => $facts['retread_count']], $dNew);
    }

    /** Auto-filled inspection information from the tire master and its actual history. */
    public function facts(Tire $tire, User $user): array
    {
        $timezone = $this->registrations->timezone($tire->tenant_id);
        $now = CarbonImmutable::now($timezone);
        $product = $tire->product()->withoutGlobalScopes()->withTrashed()->with('tireSpec')->first();
        $group = $product?->tireSpec?->vehicle_group;
        $category = self::CATEGORY_BY_GROUP[$group] ?? null;
        $manufactured = $this->manufactureDate($tire->manufacture_date_code);
        $retreads = DB::table('tire_retreads')->where('tire_id', $tire->id)->where('status', 'APPROVED')->where('approval_disposition', 'RETURN_TO_SERVICE')->count();
        $lastInstallation = DB::table('tire_installations as i')->leftJoin('vehicles as v', 'v.id', '=', 'i.vehicle_id')
            ->where('i.tire_id', $tire->id)->orderByDesc('i.installed_at')->first(['v.registration_number', 'i.wheel_position']);
        $lastRemoval = DB::table('tire_removals')->where('tire_id', $tire->id)->orderByDesc('removed_at')->first(['removal_reason', 'removed_at']);
        $repairs = DB::table('tire_repairs')->where('tire_id', $tire->id)->orderBy('cycle_number')
            ->get(['cycle_number', 'status', 'approval_disposition', 'approved_at', 'notes'])
            ->map(fn ($r) => ['source' => 'REPAIR_CYCLE', 'label' => "Repair cycle {$r->cycle_number} — {$r->status}".($r->approval_disposition ? " ({$r->approval_disposition})" : ''), 'at' => $r->approved_at])
            ->merge(TireUsedInspection::query()->where('tire_id', $tire->id)->where('status', TireUsedInspection::APPROVED)
                ->where(fn ($q) => $q->where('final_disposition', 'REPAIR')->orWhere('additional_work', 'CASING_REPAIR'))->orderBy('approved_at')->get()
                ->map(fn ($i) => ['source' => 'INSPECTION', 'label' => 'Inspection disposition '.$i->final_disposition.($i->additional_work ? ' + '.$i->additional_work : ''), 'at' => $i->approved_at?->toIso8601String()]))
            ->values()->all();

        return [
            'id' => $tire->id,
            'serial_number' => $tire->serial_number,
            'current_status' => $tire->current_status,
            'brand' => $product?->brand,
            'model' => $product?->tireSpec?->pattern_name ?? $product?->name,
            'product_name' => $product?->name,
            'size' => $tire->tire_size ?? $product?->tireSpec?->tire_size_computed,
            'construction' => $tire->construction_type ?? $product?->tireSpec?->construction_type,
            'category' => $category,
            'category_label' => self::CATEGORY_LABELS[$category] ?? null,
            'manufacture_date_code' => $tire->manufacture_date_code,
            'age_months' => $manufactured ? (int) floor($manufactured->diffInMonths($now)) : null,
            'retread_count' => $retreads,
            'repair_history' => $repairs,
            'last_vehicle' => $lastInstallation?->registration_number,
            'last_position' => $lastInstallation?->wheel_position,
            'usage_km' => $this->inventory->usage([$tire->id])[$tire->id] ?? null,
            'removal_reason' => $lastRemoval?->removal_reason,
            'inspector' => $user->name,
            'inspection_at' => $now->format('Y-m-d H:i'),
            // D_new: the product's reference depth when new; after a retread it is unknown here and is entered.
            'd_new_default_mm' => $retreads === 0 && $product?->reference_tread_depth_mm !== null ? (string) $product->reference_tread_depth_mm : null,
        ];
    }

    /** DOT date code "WWYY" (week, year) → the Monday of that ISO week; anything else is unknown. */
    public function manufactureDate(?string $code): ?CarbonImmutable
    {
        if ($code === null || ! preg_match('/^\s*(\d{2})(\d{2})\s*$/', $code, $m)) {
            return null;
        }
        [$week, $year] = [(int) $m[1], 2000 + (int) $m[2]];
        if ($week < 1 || $week > 53 || $year > (int) now()->format('Y')) {
            return null;
        }

        return CarbonImmutable::now()->setISODate($year, $week)->startOfDay();
    }

    private function summaryRow(TireUsedInspection $i): array
    {
        return [
            'id' => $i->id, 'status' => $i->status, 'inspected_at' => $i->inspected_at?->toIso8601String(),
            'recommendation' => $i->recommendation, 'recommendation_detail' => $i->recommendation_detail,
            'final_disposition' => $i->final_disposition, 'rule_profile_version' => $i->rule_profile_version,
        ];
    }

    /** Result summary wording for the inspection result page. */
    private function resultTexts(string $recommendation, ?string $detail, ?string $additionalWork, ?string $category, bool $leak, ?array $thresholds): array
    {
        $standard = self::CATEGORY_LABELS[$category] ?? 'applicable';
        $restrictions = $thresholds['application_limits'] ?? [];

        return match ($recommendation) {
            'REUSE' => ['required_work' => 'None.', 'stock_status' => 'Available — reusable used stock (REUSE).', 'return_requirement' => 'Approve the disposition and choose the warehouse.', 'usage_restrictions' => $restrictions],
            'REPAIR' => ['required_work' => "Repair according to the {$standard} tire repair standard.", 'stock_status' => 'Waiting for Repair — unavailable for installation.', 'return_requirement' => 'Repair complete + final inspection'.($leak ? ' + leak test passed' : '').'.', 'usage_restrictions' => $restrictions],
            'RETREAD' => ['required_work' => $additionalWork === 'CASING_REPAIR' ? 'Casing repair, then retread.' : 'Retread.', 'stock_status' => 'Waiting for Retread — unavailable for installation.', 'return_requirement' => 'Retread complete + final assessment + used tire inspection.', 'usage_restrictions' => $restrictions],
            'HOLD' => ['required_work' => $detail === 'RETREAD_CANDIDATE' ? 'Send the casing for the retreader\'s final inspection.' : 'Complete the open inspection items / obtain the specialist decision.', 'stock_status' => 'On Hold — unavailable for installation.', 'return_requirement' => 'Resolve the open items and inspect the tire again.', 'usage_restrictions' => $restrictions],
            default => ['required_work' => 'None — dispose of the tire.', 'stock_status' => 'Scrap — not stock (history is kept).', 'return_requirement' => 'Not applicable.', 'usage_restrictions' => []],
        };
    }
}
