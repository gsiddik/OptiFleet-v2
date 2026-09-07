<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Pricing\Models\Pricing;
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

    public function publishVersion(PublishPricingVersionRequest $request, Pricing $pricing)
    {
        $version = $this->resolution->publishVersion($pricing, $request->validated(), $request->user()->id);

        return $this->ok($version, 201);
    }
}
