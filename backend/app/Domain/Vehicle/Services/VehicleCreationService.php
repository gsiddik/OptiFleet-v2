<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityExceededException;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\Shared\Support\Messages;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Requests\Tenant\StoreVehicleRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single Create Vehicle path (New Vehicle form and Vehicle Excel import): the StoreVehicleRequest
 * rules, subscription capacity, the user's branch scope, and brand / model names derived server-side
 * from the selected Vehicle Brand / Model masters (the text columns are kept for backward compatibility).
 */
class VehicleCreationService
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Validates raw input with exactly the New Vehicle rules (for callers without an HTTP request).
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $request = new StoreVehicleRequest;
        $request->merge($input); // the model rule reads vehicle_brand_id from the input

        return Validator::make($input, $request->rules())->validate();
    }

    /**
     * @param  array<string, mixed>  $validated  fields that passed StoreVehicleRequest
     *
     * @throws ValidationException|CapacityExceededException
     */
    public function create(string $tenantId, array $validated, User $user): Vehicle
    {
        $this->capacity->assertCanCreate($tenantId, 'vehicle');
        if (! $this->scope->canAccessBranch($user, $tenantId, $validated['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => Messages::localized('vehicle.import.branchOutOfScope')]);
        }

        return Vehicle::query()->create(array_merge($validated, [
            'brand' => VehicleBrand::query()->findOrFail($validated['vehicle_brand_id'])->name,
            'model' => VehicleModelMaster::query()->findOrFail($validated['vehicle_model_id'])->name,
            'status' => 'ACTIVE',
            'operational_status' => $validated['operational_status'] ?? 'AVAILABLE',
        ]));
    }
}
