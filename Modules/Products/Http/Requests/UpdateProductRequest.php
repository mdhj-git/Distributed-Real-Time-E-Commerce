<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // JSON only: images are uploaded by their own endpoint, and multipart
        // would fail silently on PATCH anyway.
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
        ];
    }
}
