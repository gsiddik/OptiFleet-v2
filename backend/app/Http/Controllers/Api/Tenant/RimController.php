<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\Rim;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreRimRequest;
use App\Http\Requests\Tenant\UpdateRimRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class RimController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = Rim::query()->where('tenant_id', $this->context->tenantId());

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('brand', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('brand')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreRimRequest $request)
    {
        $rim = Rim::query()->create($request->validated() + [
            'tenant_id' => $this->context->tenantId(),
            'status' => $request->input('status', 'ACTIVE'),
        ]);

        return $this->ok($rim, 201);
    }

    public function show(Rim $rim)
    {
        $this->authorizeScope($rim);

        return $this->ok($rim);
    }

    public function update(UpdateRimRequest $request, Rim $rim)
    {
        $this->authorizeScope($rim);
        $rim->update($request->validated());

        return $this->ok($rim->fresh());
    }

    public function destroy(Rim $rim)
    {
        $this->authorizeScope($rim);
        $rim->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeScope(Rim $rim): void
    {
        abort_unless($rim->tenant_id === $this->context->tenantId(), 404);
    }
}
