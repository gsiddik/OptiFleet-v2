<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\Concerns\ValidatesComponentClassification;
use Illuminate\Foundation\Http\FormRequest;

class StoreComponentSubcategoryRequest extends FormRequest
{
    use ValidatesComponentClassification;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['component_category_id' => ['required', 'uuid'], 'code' => $this->codeRules()] + $this->commonRules(creating: true) + $this->itemTypeRules();
    }
}
