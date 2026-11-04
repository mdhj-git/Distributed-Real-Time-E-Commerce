<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'initial_stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
