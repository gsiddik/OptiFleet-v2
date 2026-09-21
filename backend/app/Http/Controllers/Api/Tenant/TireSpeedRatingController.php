<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\TireSpeedRating;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TireSpeedRatingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = TireSpeedRating::query();
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
            'code' => ['required', 'string', 'max:10', Rule::unique('tire_speed_ratings', 'code')->where('tenant_id', $tenantId)],
            'max_speed_kmh' => ['nullable', 'numeric', 'min:0'],
        ]);

        $speedRating = TireSpeedRating::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($speedRating, 201);
    }

    public function update(Request $request, TireSpeedRating $tireSpeedRating)
    {
        $this->authorizeVisible($tireSpeedRating);
        abort_if($tireSpeedRating->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'max_speed_kmh' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $tireSpeedRating->update($validated);

        return $this->ok($tireSpeedRating->fresh());
    }

    public function destroy(TireSpeedRating $tireSpeedRating)
    {
        $this->authorizeVisible($tireSpeedRating);
        abort_if($tireSpeedRating->is_system, 403, 'System master data cannot be modified by a tenant.');

        $tireSpeedRating->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(TireSpeedRating $tireSpeedRating): void
    {
        abort_unless($tireSpeedRating->tenant_id === null || $tireSpeedRating->tenant_id === $this->context->tenantId(), 404);
    }
}
