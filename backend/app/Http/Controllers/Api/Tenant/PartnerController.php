<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Services\PartnerPerformanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StorePartnerRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function __construct(
        private readonly PartnerPerformanceService $performance,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = Partner::query()->where('tenant_id', $this->context->tenantId());

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if (is_array($request->input('partner_type'))) {
            $query->whereIn('partner_type', $request->input('partner_type'));
        } elseif ($value = $request->string('partner_type')->value()) {
            $query->where('partner_type', $value);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StorePartnerRequest $request)
    {
        $partner = Partner::query()->create($request->validated() + ['tenant_id' => $this->context->tenantId(), 'status' => 'ACTIVE']);

        return $this->ok($partner, 201);
    }

    public function show(Partner $partner)
    {
        $this->authorizeScope($partner);

        return $this->ok(array_merge($partner->toArray(), ['performance' => $this->performance->summary($partner)]));
    }

    public function update(Request $request, Partner $partner)
    {
        $this->authorizeScope($partner);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $partner->update($validated);

        return $this->ok($partner->fresh());
    }

    private function authorizeScope(Partner $partner): void
    {
        abort_unless($partner->tenant_id === $this->context->tenantId(), 404);
    }
}
