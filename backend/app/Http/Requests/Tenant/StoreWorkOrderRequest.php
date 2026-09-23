<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'workshop_id' => ['nullable', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $tenantId)],
            // "Improvement OptiFleet - Maintenance Request dan Work Order": the manual New Work
            // Order popup only ever offers Corrective/Breakdown — Preventive is set exclusively by
            // the Planning & Schedule -> Work Order conversion path (WorkOrderService::
            // fromMaintenanceSchedule), never this request class.
            'maintenance_type' => ['required', 'in:CORRECTIVE,BREAKDOWN'],
            'priority' => ['nullable', 'in:LOW,MEDIUM,HIGH,URGENT'],
            'complaint' => ['nullable', 'string'],
            'current_odometer' => ['required', 'numeric', 'min:0'],
            'engine_hour' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
