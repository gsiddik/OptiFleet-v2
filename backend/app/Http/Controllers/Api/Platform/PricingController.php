<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Pricing\Models\Pricing;
use App\Domain\Pricing\Services\PricingException;
use App\Domain\Pricing\Services\PricingResolutionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PublishPricingVersionRequest;
use App\Http\Requests\Platform\StorePricingRequest;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(private readonly PricingResolutionService $resolution) {}

    public function index(Request $request)
    {
        $query = Pricing::query()->with(['versions' => fn ($q) => $q->orderByDesc('version_number')]);

        if ($type = $request->string('priceable_type')->value()) {
            $query->where('priceable_type', $type);
        }
        if ($code = $request->string('priceable_code')->value()) {
            $query->where('priceable_code', $code);
        }
        if ($request->boolean('active_only')) {
            // Used by the Contract Form's Product Reference dropdown
            // (Section 10.2): only ACTIVE, non-deleted pricing can be
            // selected for a new line item. Soft-deleted rows are already
            // excluded by Pricing's default query scope.
            $query->where('status', 'ACTIVE');
        }

        return $this->ok($query->orderBy('priceable_type')->orderBy('priceable_code')->get());
    }

    public function store(StorePricingRequest $request)
    {
        $pricing = Pricing::query()->firstOrCreate([
            'priceable_type' => $request->input('priceable_type'),
            'priceable_code' => $request->input('priceable_code'),
            'billing_frequency' => $request->input('billing_frequency'),
        ], [
            'pricing_method' => $request->input('pricing_method'),
            'currency' => $request->input('currency', 'IDR'),
            'status' => 'DRAFT',
        ]);

        $version = $this->resolution->publishVersion($pricing, $request->only(['amount', 'tiers', 'effective_from', 'effective_until']), $request->user()->id);

        return $this->ok(['pricing' => $pricing->fresh(), 'version' => $version], 201);
    }

    public function show(Pricing $pricing)
    {
        return $this->ok($pricing->load('versions'));
    }

    /**
     * Preview-only Active Price lookup for the Contract Form's Unit Price
     * auto-fill (Section 10.5). Purely informational — the contract-save
     * path in ContractService::addItem re-resolves and re-validates this
     * itself, so a stale preview here can never let a stale/low price
     * through when the contract is actually saved.
     */
    public function resolve(Request $request)
    {
        $data = $request->validate([
            'priceable_type' => ['required', 'in:MODULE,BUNDLE,ADD_ON,CAPACITY'],
            'priceable_code' => ['required', 'string'],
            'billing_frequency' => ['required', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'date' => ['nullable', 'date'],
        ]);

        $resolved = $this->resolution->resolveForTenant(
            $data['tenant_id'],
            $data['priceable_type'],
            $data['priceable_code'],
            $data['billing_frequency'],
            $data['date'] ?? null,
        );

        return $this->ok($resolved);
    }

    public function publishVersion(PublishPricingVersionRequest $request, Pricing $pricing)
    {
        $version = $this->resolution->publishVersion($pricing, $request->validated(), $request->user()->id);

        return $this->ok($version, 201);
    }

    /**
     * Deactivate: no longer selectable for new contracts/transactions, but
     * still resolvable by id for anything historical (Section 8.1). Reuses
     * the existing ARCHIVED status value rather than introducing a new one.
     */
    public function deactivate(Pricing $pricing)
    {
        if ($pricing->status !== 'ACTIVE') {
            throw new PricingException('Only an ACTIVE pricing can be deactivated.');
        }

        $pricing->update(['status' => 'ARCHIVED']);

        return $this->ok($pricing->fresh());
    }

    public function reactivate(Pricing $pricing)
    {
        if ($pricing->status !== 'ARCHIVED') {
            throw new PricingException('Only an ARCHIVED pricing can be reactivated.');
        }

        $pricing->update(['status' => 'ACTIVE']);

        return $this->ok($pricing->fresh());
    }

    public function destroy(Pricing $pricing)
    {
        $pricing->delete();

        return $this->ok(['deleted' => true]);
    }
}
