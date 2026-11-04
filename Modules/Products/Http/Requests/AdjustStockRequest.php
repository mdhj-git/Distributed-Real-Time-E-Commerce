<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // JSON only: adjustment is a signed integer, never a multipart field.
        return [
            'adjustment' => ['required', 'integer', 'not_in:0'],
        ];
    }
}
