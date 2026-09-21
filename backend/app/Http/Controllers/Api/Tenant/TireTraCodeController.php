<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\TireTraCode;
use App\Domain\Tire\Models\TireTraStarRating;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TireTraCodeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = TireTraCode::query();
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where('code', 'ilike', "%{$search}%");
        }

        return $this->paginated($query->orderBy('code')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('tire_tra_codes', 'code')->where('tenant_id', $tenantId)],
            'profile' => ['nullable', 'string', 'max:50'],
        ]);

        $traCode = TireTraCode::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($traCode, 201);
    }

    public function show(TireTraCode $tireTraCode)
    {
        $this->authorizeVisible($tireTraCode);

        return $this->ok($tireTraCode->load('starRatings'));
    }

    public function update(Request $request, TireTraCode $tireTraCode)
    {
        $this->authorizeVisible($tireTraCode);
        abort_if($tireTraCode->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'profile' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $tireTraCode->update($validated);

        return $this->ok($tireTraCode->fresh());
    }

    public function destroy(TireTraCode $tireTraCode)
    {
        $this->authorizeVisible($tireTraCode);
        abort_if($tireTraCode->is_system, 403, 'System master data cannot be modified by a tenant.');

        $tireTraCode->delete();

        return $this->ok(['deleted' => true]);
    }

    public function storeStarRating(Request $request, TireTraCode $tireTraCode)
    {
        $this->authorizeVisible($tireTraCode);

        $validated = $request->validate([
            'star_rating' => ['required', 'string', 'max:10', Rule::unique('tire_tra_star_ratings', 'star_rating')->where('tra_code_id', $tireTraCode->id)],
            'purpose' => ['nullable', 'string', 'max:255'],
        ]);

        $starRating = $tireTraCode->starRatings()->create($validated + ['tenant_id' => $this->context->tenantId()]);

        return $this->ok($starRating, 201);
    }

    public function updateStarRating(Request $request, TireTraCode $tireTraCode, TireTraStarRating $starRating)
    {
        $this->authorizeVisible($tireTraCode);
        abort_unless($starRating->tra_code_id === $tireTraCode->id, 404);

        $validated = $request->validate([
            'purpose' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $starRating->update($validated);

        return $this->ok($starRating->fresh());
    }

    public function destroyStarRating(TireTraCode $tireTraCode, TireTraStarRating $starRating)
    {
        $this->authorizeVisible($tireTraCode);
        abort_unless($starRating->tra_code_id === $tireTraCode->id, 404);

        $starRating->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(TireTraCode $tireTraCode): void
    {
        abort_unless($tireTraCode->tenant_id === null || $tireTraCode->tenant_id === $this->context->tenantId(), 404);
    }
}
