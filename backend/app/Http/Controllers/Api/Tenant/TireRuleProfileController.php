<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\TireRuleProfile;
use App\Domain\Tire\Models\TireUsedInspectionDamage;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Used tire inspection rule profiles: thresholds per tire category (+ optional tire product
 * = brand/model, + optional application). Viewing needs tire.view; changing needs
 * tire_rule_profile.manage. Every change bumps the version (inspections keep the version used).
 */
class TireRuleProfileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        return $this->ok(TireRuleProfile::query()->with('product:id,name,brand')
            ->when($request->filled('tire_category'), fn ($q) => $q->where('tire_category', $request->string('tire_category')->value()))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('status', 'ACTIVE'))
            ->orderBy('tire_category')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $userId = $this->context->user()->id;

        return $this->ok($this->persist(fn () => TireRuleProfile::query()->create($data + [
            'tenant_id' => $this->context->tenantId(), 'version' => 1, 'status' => 'ACTIVE', 'created_by' => $userId, 'updated_by' => $userId,
        ])), 201);
    }

    public function update(Request $request, TireRuleProfile $tireRuleProfile)
    {
        $data = $this->validated($request);

        return $this->ok($this->persist(function () use ($tireRuleProfile, $data) {
            $tireRuleProfile->update($data + ['version' => $tireRuleProfile->version + 1, 'updated_by' => $this->context->user()->id]);

            return $tireRuleProfile->fresh();
        }));
    }

    public function deactivate(TireRuleProfile $tireRuleProfile)
    {
        $tireRuleProfile->update(['status' => 'INACTIVE', 'version' => $tireRuleProfile->version + 1, 'updated_by' => $this->context->user()->id]);

        return $this->ok($tireRuleProfile->fresh());
    }

    private function persist(callable $write): TireRuleProfile
    {
        try {
            // Savepoint: a unique violation rolls back only this write.
            return DB::transaction(fn () => $write());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['tire_category' => 'An active profile already exists for this category, tire product and application.']);
        }
    }

    private function validated(Request $request): array
    {
        $tenantId = $this->context->tenantId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'tire_category' => ['required', Rule::in(TireRuleProfile::CATEGORIES)],
            'product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where(fn ($q) => $q->where('product_type', 'TIRE')->where(fn ($w) => $w->where('tenant_id', $tenantId)->orWhereNull('tenant_id')))],
            'application' => ['nullable', 'string', 'max:50'],
            'd_service_mm' => ['required', 'numeric', 'gt:0', 'max:99.99'],
            'd_pull_mm' => ['required', 'numeric', 'gte:d_service_mm', 'max:99.99'],
            'a_max_months' => ['required', 'integer', 'between:1,600'],
            'a_retread_max_months' => ['required', 'integer', 'between:1,600'],
            'n_retread_max' => ['required', 'integer', 'between:0,20'],
            'repair_limits' => ['required', 'array'],
            'repair_limits.allowed_locations' => ['present', 'array'],
            'repair_limits.allowed_locations.*' => [Rule::in(TireUsedInspectionDamage::LOCATIONS)],
            'repair_limits.max_puncture_diameter_mm' => ['nullable', 'numeric', 'min:0'],
            'repair_limits.max_cut_length_mm' => ['nullable', 'numeric', 'min:0'],
            'repair_limits.max_cut_width_mm' => ['nullable', 'numeric', 'min:0'],
            'repair_limits.max_cut_depth_mm' => ['nullable', 'numeric', 'min:0'],
            'repair_limits.max_repairs' => ['nullable', 'integer', 'between:0,50'],
            'repair_limits.allow_overlap_previous_repair' => ['required', 'boolean'],
            'repair_limits.allow_reinforcement_damage' => ['required', 'boolean'],
            'application_limits' => ['nullable', 'array'],
            'application_limits.positions' => ['nullable', 'array'],
            'application_limits.positions.*' => ['string', 'max:50'],
            'application_limits.max_load_kg' => ['nullable', 'numeric', 'min:0'],
            'application_limits.max_speed_kmh' => ['nullable', 'numeric', 'min:0'],
            'application_limits.operations' => ['nullable', 'array'],
            'application_limits.operations.*' => ['string', 'max:100'],
            'application_limits.notes' => ['nullable', 'string', 'max:500'],
        ], ['d_pull_mm.gte' => 'D_pull must be greater than or equal to D_service.']);

        $data['application'] = isset($data['application']) && trim($data['application']) !== '' ? trim($data['application']) : null;
        $data['application_limits'] = $data['application_limits'] ?? [];
        $data['repair_limits']['allowed_locations'] = array_values(array_unique($data['repair_limits']['allowed_locations']));

        return $data;
    }
}
