<?php

namespace Modules\Orders\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Http\Requests\CreateOrderRequest;
use Modules\Orders\Http\Requests\ListOrdersRequest;
use Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use Modules\Orders\Http\Resources\OrderResource;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderService;
use Modules\Shared\Helpers\ApiResponse;

class OrderController
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $perPage = (int) $request->validated('per_page', 15);

        // The admin sees all orders; a customer's query is filtered by their own id.
        $ownerId = Gate::allows('viewAll', Order::class) ? null : (int) auth()->id();

        return ApiResponse::paginated(
            $this->orders->paginate($ownerId, $perPage),
            OrderResource::class,
        );
    }

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create((int) auth()->id(), $request->validated('items'));

        return ApiResponse::created(new OrderResource($order), route('api.orders.show', $order));
    }

    public function show(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return ApiResponse::ok(new OrderResource($order->load('items')));
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $updated = $this->orders->changeStatus(
            $order->id,
            OrderStatus::from($request->validated('status')),
        );

        return ApiResponse::ok(new OrderResource($updated));
    }
}
