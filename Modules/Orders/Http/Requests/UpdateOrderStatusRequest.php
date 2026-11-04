<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Orders\Enums\OrderStatus;

class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * The admin role is enforced by the can:admin route middleware, which runs before
     * validation, so a customer always gets 403 and never a 422.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(OrderStatus::class)],
        ];
    }
}
