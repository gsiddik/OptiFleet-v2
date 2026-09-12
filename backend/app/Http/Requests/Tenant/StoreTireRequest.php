<?php

namespace App\Http\Requests\Tenant;

use App\Domain\Tire\Models\Tire;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreTireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** G-26: trim before validation runs, so the uniqueness check and the stored value agree. */
    protected function prepareForValidation(): void
    {
        if ($this->has('serial_number') && is_string($this->input('serial_number'))) {
            $this->merge(['serial_number' => trim($this->input('serial_number'))]);
        }
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'serial_number' => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($tenantId) {
                    $exists = Tire::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(trim(serial_number)) = ?', [Str::lower(trim($value))])
                        ->exists();
                    if ($exists) {
                        $fail('This serial number (case/whitespace-insensitive) is already registered for this tenant.');
                    }
                },
            ],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'manufacture_date_code' => ['nullable', 'string', 'max:20'],
            'tire_size' => ['nullable', 'string', 'max:50'],
            'pattern' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'warranty_km' => ['nullable', 'integer', 'min:0'],
            'current_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
        ];
    }
}
