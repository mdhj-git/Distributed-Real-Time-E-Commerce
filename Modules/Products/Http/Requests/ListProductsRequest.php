<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Empty query values arrive as null after Laravel's
            // ConvertEmptyStringsToNull, so "nullable" means "no filter".
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Over the limit is rejected, never silently clamped.
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
