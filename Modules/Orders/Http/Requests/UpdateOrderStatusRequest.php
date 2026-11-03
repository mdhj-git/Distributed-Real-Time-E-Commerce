<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Orders\Enums\OrderStatus;

class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so a customer always gets 403 and never a 422.
     */
    public function authorize(): bool
    {
        return Gate::allows('updateStatus', $this->route('order'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(OrderStatus::class)],
        ];
    }
}
