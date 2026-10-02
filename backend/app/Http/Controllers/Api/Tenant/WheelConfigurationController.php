<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\WheelConfiguration;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use App\Domain\Tire\Services\WheelPositionCatalog;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WheelConfigurationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WheelPositionCatalog $catalog,
    ) {}

    /**
     * Positions that apply to the tenant: its own rows, plus platform defaults for categories the
     * tenant has not saved a versioned configuration for. RETIRED positions (history only) are
     * excluded unless include_retired=1.
     */
    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $versionedCategories = WheelConfigurationVersion::query()
            ->where('tenant_id', $tenantId)->where('status', WheelConfigurationVersion::STATUS_ACTIVE)
            ->select('vehicle_category_id');
        $query = WheelConfiguration::query()->where(fn ($q) => $q->where('tenant_id', $tenantId)
            ->orWhere(fn ($p) => $p->whereNull('tenant_id')->whereNotIn('vehicle_category_id', $versionedCategories)));

        if (! $request->boolean('include_retired')) {
            $query->where('status', WheelConfiguration::STATUS_ACTIVE);
        }
        if ($categoryId = $request->string('vehicle_category_id')->value()) {
            $query->where('vehicle_category_id', $categoryId);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('position_code', 'ilike', "%{$search}%")->orWhere('label', 'ilike', "%{$search}%"));
        }

        return $this->ok($query->with('vehicleCategory')->orderBy('vehicle_category_id')->orderBy('sequence')->get());
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'vehicle_category_id' => ['required', 'uuid', 'exists:vehicle_categories,id'],
            'position_code' => ['required', 'string', 'max:20'],
            'label' => ['required', 'string', 'max:100'],
            'axle_number' => ['nullable', 'integer', 'min:1'],
            'sequence' => ['nullable', 'integer', 'min:0'],
        ]);

        $config = DB::transaction(function () use ($tenantId, $validated) {
            $this->catalog->lock($tenantId, $validated['vehicle_category_id'], exclusive: true);
            $this->assertNotVersioned($tenantId, $validated['vehicle_category_id']);

            return WheelConfiguration::query()->create($validated + ['tenant_id' => $tenantId]);
        });

        return $this->ok($config, 201);
    }

    public function update(Request $request, WheelConfiguration $wheelConfiguration)
    {
        $this->authorizeTenantOwned($wheelConfiguration);

        $validated = $request->validate([
            'position_code' => ['sometimes', 'string', 'max:20'],
            'label' => ['sometimes', 'string', 'max:100'],
            'axle_number' => ['nullable', 'integer', 'min:1'],
            'sequence' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($wheelConfiguration, $validated) {
            $this->catalog->lock($wheelConfiguration->tenant_id, $wheelConfiguration->vehicle_category_id, exclusive: true);
            $this->assertNotVersioned($wheelConfiguration->tenant_id, $wheelConfiguration->vehicle_category_id);
            $wheelConfiguration->update($validated);
        });

        return $this->ok($wheelConfiguration->fresh());
    }

    /**
     * Never removes a position a tire is installed on. A position referenced by installation or
     * rotation history is RETIRED instead of deleted, so that history stays resolvable.
     */
    public function destroy(WheelConfiguration $wheelConfiguration)
    {
        $this->authorizeTenantOwned($wheelConfiguration);

        $retired = DB::transaction(function () use ($wheelConfiguration) {
            $tenantId = $wheelConfiguration->tenant_id;
            $categoryId = $wheelConfiguration->vehicle_category_id;
            $code = $wheelConfiguration->position_code;
            $this->catalog->lock($tenantId, $categoryId, exclusive: true);
            $this->assertNotVersioned($tenantId, $categoryId);

            $vehicles = DB::table('vehicles')->where('tenant_id', $tenantId)->where('vehicle_category_id', $categoryId)->select('id');
            $installed = DB::table('tire_installations')->where('tenant_id', $tenantId)->whereIn('vehicle_id', $vehicles)
                ->where('wheel_position', $code)->whereNull('removed_at')->exists();
            if ($installed) {
                throw ValidationException::withMessages(['position_code' => "A tire is installed on position {$code}. Remove or transfer it first."]);
            }

            $referenced = DB::table('tire_installations')->where('tenant_id', $tenantId)->whereIn('vehicle_id', $vehicles)->where('wheel_position', $code)->exists()
                || DB::table('tire_rotations')->where('tenant_id', $tenantId)->whereIn('vehicle_id', $vehicles)
                    ->where(fn ($q) => $q->where('from_position', $code)->orWhere('to_position', $code))->exists();
            if ($referenced) {
                $wheelConfiguration->update(['status' => WheelConfiguration::STATUS_RETIRED, 'retired_at' => now()]);

                return true;
            }
            $wheelConfiguration->delete();

            return false;
        });

        return $this->ok(['deleted' => ! $retired, 'retired' => $retired]);
    }

    /**
     * Platform-default rows (tenant_id null) are a shared read-only baseline
     * layout, not tenant-editable — only a tenant's own rows may be changed.
     */
    private function authorizeTenantOwned(WheelConfiguration $wheelConfiguration): void
    {
        abort_unless($wheelConfiguration->tenant_id === $this->context->tenantId(), 404);
    }

    /** Positions of a versioned configuration change only through a new version (New Wheels Configuration). */
    private function assertNotVersioned(string $tenantId, string $vehicleCategoryId): void
    {
        if ($this->catalog->activeVersion($tenantId, $vehicleCategoryId)) {
            throw ValidationException::withMessages([
                'vehicle_category_id' => 'This vehicle category uses a saved wheel configuration; change it through New Wheels Configuration.',
            ]);
        }
    }
}
