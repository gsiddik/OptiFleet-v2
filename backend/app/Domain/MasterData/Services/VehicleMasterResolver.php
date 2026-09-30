<?php

namespace App\Domain\MasterData\Services;

use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a Vehicle Brand / Vehicle Model selection (by id) into the stored reference plus
 * the name snapshot kept alongside it. A brand must be the tenant's own or a platform brand,
 * active and not deleted; a model must be active, not deleted and belong to that brand.
 * A value the record already holds stays accepted on edit, even once it is retired.
 */
class VehicleMasterResolver
{
    /**
     * @param  array{vehicle_brand_id?: ?string, vehicle_model_id?: ?string}|null  $current  the stored selection, for edits
     * @return array{vehicle_brand_id: ?string, vehicle_brand: ?string, vehicle_model_id: ?string, vehicle_model: ?string}
     */
    public function resolve(?string $brandId, ?string $modelId, ?string $tenantId, string $prefix = '', bool $required = false, ?array $current = null): array
    {
        if ($brandId === null || $brandId === '') {
            if ($required || ($modelId !== null && $modelId !== '')) {
                throw ValidationException::withMessages([$prefix.'vehicle_brand_id' => 'Select a Vehicle Brand.']);
            }

            return ['vehicle_brand_id' => null, 'vehicle_brand' => null, 'vehicle_model_id' => null, 'vehicle_model' => null];
        }

        $brand = VehicleBrand::query()->withoutGlobalScopes()->whereKey($brandId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q2) => $q2->orWhere('tenant_id', $tenantId)))
            ->first();
        $unchangedBrand = ($current['vehicle_brand_id'] ?? null) === $brandId;
        if (! $brand || (! $unchangedBrand && $brand->status !== 'ACTIVE')) {
            throw ValidationException::withMessages([$prefix.'vehicle_brand_id' => 'The selected Vehicle Brand is not available.']);
        }

        if ($modelId === null || $modelId === '') {
            if ($required) {
                throw ValidationException::withMessages([$prefix.'vehicle_model_id' => 'Select a Vehicle Model.']);
            }

            return ['vehicle_brand_id' => $brand->id, 'vehicle_brand' => $brand->name, 'vehicle_model_id' => null, 'vehicle_model' => null];
        }

        $model = VehicleModel::query()->withoutGlobalScopes()->whereKey($modelId)->whereNull('deleted_at')->first();
        $unchangedModel = $unchangedBrand && ($current['vehicle_model_id'] ?? null) === $modelId;
        if (! $model || $model->vehicle_brand_id !== $brand->id) {
            throw ValidationException::withMessages([$prefix.'vehicle_model_id' => 'The selected Vehicle Model does not belong to the selected Vehicle Brand.']);
        }
        if (! $unchangedModel && $model->status !== 'ACTIVE') {
            throw ValidationException::withMessages([$prefix.'vehicle_model_id' => 'The selected Vehicle Model is not available.']);
        }

        return ['vehicle_brand_id' => $brand->id, 'vehicle_brand' => $brand->name, 'vehicle_model_id' => $model->id, 'vehicle_model' => $model->name];
    }
}
