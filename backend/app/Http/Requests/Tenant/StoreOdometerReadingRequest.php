<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/** A person's odometer entry for one vehicle. Rules that depend on the vehicle (lower value, older measurement) are in OdometerService. */
class StoreOdometerReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'odometer_km' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'measured_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'uuid'],
            'correction_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
