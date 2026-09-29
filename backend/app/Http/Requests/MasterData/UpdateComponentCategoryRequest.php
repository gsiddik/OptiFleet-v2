<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\Concerns\ValidatesComponentClassification;
use Illuminate\Foundation\Http\FormRequest;

class UpdateComponentCategoryRequest extends FormRequest
{
    use ValidatesComponentClassification;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Code is immutable after creation; re-parenting is checked for usage by the service.
        return ['component_group_id' => ['sometimes', 'uuid']] + $this->commonRules(creating: false);
    }
}
