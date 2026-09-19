<?php

namespace App\Http\Requests\Tenant;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequestInspectionGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveMaintenanceRequestAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
            'groups' => ['required', 'array', 'size:'.count(MaintenanceRequestInspectionGroup::GROUP_CODES)],
            'groups.*.group_code' => ['required', 'string', Rule::in(MaintenanceRequestInspectionGroup::GROUP_CODES)],
            'groups.*.status' => ['required', 'string', Rule::in(MaintenanceRequestInspectionGroup::STATUSES)],
            'groups.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $codes = collect($this->input('groups', []))->pluck('group_code')->filter()->all();
            $missing = array_diff(MaintenanceRequestInspectionGroup::GROUP_CODES, $codes);
            $duplicates = array_diff_assoc($codes, array_unique($codes));

            if ($missing !== []) {
                $validator->errors()->add('groups', 'All 14 inspection groups are required. Missing: '.implode(', ', $missing));
            }
            if ($duplicates !== []) {
                $validator->errors()->add('groups', 'Each inspection group may only appear once. Duplicated: '.implode(', ', array_unique($duplicates)));
            }
        });
    }
}
