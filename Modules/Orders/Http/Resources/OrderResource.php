<?php

namespace Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Orders\Models\Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'total' => (float) $this->total,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
