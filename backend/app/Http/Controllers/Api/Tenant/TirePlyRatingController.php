<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\TirePlyRating;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TirePlyRatingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = TirePlyRating::query();
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
            'code' => ['required', 'string', 'max:20', Rule::unique('tire_ply_ratings', 'code')->where('tenant_id', $tenantId)],
            'load_range' => ['nullable', 'string', 'max:10'],
        ]);

        $plyRating = TirePlyRating::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($plyRating, 201);
    }

    public function update(Request $request, TirePlyRating $tirePlyRating)
    {
        $this->authorizeVisible($tirePlyRating);
        abort_if($tirePlyRating->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'load_range' => ['sometimes', 'nullable', 'string', 'max:10'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $tirePlyRating->update($validated);

        return $this->ok($tirePlyRating->fresh());
    }

    public function destroy(TirePlyRating $tirePlyRating)
    {
        $this->authorizeVisible($tirePlyRating);
        abort_if($tirePlyRating->is_system, 403, 'System master data cannot be modified by a tenant.');

        $tirePlyRating->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(TirePlyRating $tirePlyRating): void
    {
        abort_unless($tirePlyRating->tenant_id === null || $tirePlyRating->tenant_id === $this->context->tenantId(), 404);
    }
}
