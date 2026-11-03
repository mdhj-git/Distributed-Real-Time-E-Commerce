<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Orders\Models\Order;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Order::class);
    }

    /**
     * Price and total are deliberately not accepted: only validated fields are ever read.
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
