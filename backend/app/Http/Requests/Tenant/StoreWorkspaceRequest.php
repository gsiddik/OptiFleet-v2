<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'workshop_id' => ['required', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'workspace_type' => ['required', 'in:GENERAL_SERVICE_BAY,HEAVY_VEHICLE_BAY,INSPECTION_BAY,ELECTRICAL_BAY,TIRE_BAY,QC_BAY,WASHING_BAY,PARKING_LOT,HOLDING_AREA,OTHER'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'capacity_unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
