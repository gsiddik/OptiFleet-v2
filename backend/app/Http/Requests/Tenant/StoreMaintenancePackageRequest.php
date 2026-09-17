<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Section 10: the NEW package-creation workflow only offers PREVENTIVE and
 * PERIODIC — CORRECTIVE/BREAKDOWN/INSPECTION/CAMPAIGN packages already in
 * the database (created before this change) are untouched and keep working
 * everywhere else; this request only governs what a *new* package may be.
 */
class StoreMaintenancePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('maintenance_packages', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'maintenance_type' => ['required', 'in:PREVENTIVE,PERIODIC'],
            'description' => ['nullable', 'string'],
            'standard_labor_hours' => ['nullable', 'numeric', 'min:0'],
            'period_by' => ['required', 'in:CALENDAR_DAY,MONTH,ODOMETER,ENGINE_HOUR'],
            'threshold_days' => ['nullable', 'integer', 'min:0'],
            'threshold_month' => ['nullable', 'integer', 'min:0'],
            'threshold_km' => ['nullable', 'integer', 'min:0'],
            'threshold_engine_hour' => ['nullable', 'integer', 'min:0'],
            'schedule_period' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('maintenance_type');
            $periodBy = $this->input('period_by');
            $thresholdColumn = [
                'CALENDAR_DAY' => 'threshold_days',
                'MONTH' => 'threshold_month',
                'ODOMETER' => 'threshold_km',
                'ENGINE_HOUR' => 'threshold_engine_hour',
            ][$periodBy] ?? null;

            if ($type === 'PERIODIC' && in_array($periodBy, ['ODOMETER', 'ENGINE_HOUR'], true)) {
                $validator->errors()->add('period_by', 'A periodic package can only be scheduled by calendar day or month.');
            }

            if ($type === 'PERIODIC') {
                if (! $this->filled('schedule_period') || (int) $this->input('schedule_period') <= 0) {
                    $validator->errors()->add('schedule_period', 'Schedule Period is required for a periodic package and must be greater than 0.');
                }
            }

            if ($type === 'PREVENTIVE' && $thresholdColumn !== null) {
                $primary = $this->input($thresholdColumn);
                if ($primary === null || (int) $primary <= 0) {
                    $validator->errors()->add($thresholdColumn, 'The primary threshold is required and must be greater than 0.');
                }
            }
        });
    }
}
