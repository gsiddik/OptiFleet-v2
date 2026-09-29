<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\Concerns\ValidatesComponentClassification;
use Illuminate\Foundation\Http\FormRequest;

class UpdateComponentSubcategoryRequest extends FormRequest
{
    use ValidatesComponentClassification;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['component_category_id' => ['sometimes', 'uuid']] + $this->commonRules(creating: false) + $this->itemTypeRules();
    }
}
