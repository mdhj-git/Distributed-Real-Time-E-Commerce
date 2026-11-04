<?php

namespace Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Orders\Models\OrderItem
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            // Decimal columns arrive as strings, so they are cast to numbers here.
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
        ];
    }
}
