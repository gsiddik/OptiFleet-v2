<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\Concerns\ValidatesComponentClassification;
use Illuminate\Foundation\Http\FormRequest;

class StoreComponentCategoryRequest extends FormRequest
{
    use ValidatesComponentClassification;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['component_group_id' => ['required', 'uuid'], 'code' => $this->codeRules()] + $this->commonRules(creating: true);
    }
}
