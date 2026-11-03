<?php

namespace Modules\Orders\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Exceptions\InvalidStatusTransitionException;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Contracts\DTOs\StockItem;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Contracts\ProductCatalog;
use Modules\Shared\Helpers\Money;

class OrderService
{
    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     */
    public function create(int $userId, array $items): Order
    {
        $quantities = $this->mergeItems($items);

        $order = DB::transaction(function () use ($userId, $items, $quantities): Order {
            $snapshots = $this->catalog->findForOrder(array_keys($quantities));

            $this->assertProductsExist($items, $snapshots);

            // $quantities is sorted by product_id, so every request locks stock rows in the same order.
            $this->inventory->reserve(array_map(
                fn (int $productId, int $quantity) => new StockItem($productId, $quantity),
                array_keys($quantities),
                $quantities,
            ));

            // Price and total come from the server's product data only, in integer cents.
            $totalCents = 0;
            $rows = [];
            foreach ($quantities as $productId => $quantity) {
                $snapshot = $snapshots[$productId];
                $totalCents += $snapshot->priceCents * $quantity;
                $rows[] = [
                    'product_id' => $productId,
                    'product_name' => $snapshot->name,
                    'unit_price' => Money::fromCents($snapshot->priceCents),
                    'quantity' => $quantity,
                ];
            }

            $order = Order::create([
                'user_id' => $userId,
                'status' => OrderStatus::Pending,
                'total' => Money::fromCents($totalCents),
            ]);
            $order->items()->createMany($rows);

            return $order->load('items');
        });

        // Dispatched only after the transaction has committed.
        OrderPlaced::dispatch($order->id, $order->user_id);

        return $order;
    }

    /**
     * @param  int|null  $ownerId  null means "all orders" and is only passed for the admin.
     */
    public function paginate(?int $ownerId, int $perPage): LengthAwarePaginator
    {
        // The query itself is filtered by owner, so another user's rows are never loaded.
        return Order::query()
            ->with('items')
            ->when($ownerId !== null, fn ($query) => $query->where('user_id', $ownerId))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function changeStatus(int $orderId, OrderStatus $to): Order
    {
        [$order, $from] = DB::transaction(function () use ($orderId, $to): array {
            // The row lock makes a concurrent second change wait, then see the new status and be refused.
            // Without it two simultaneous cancellations would both pass and release the stock twice.
            $order = Order::query()->with('items')->lockForUpdate()->findOrFail($orderId);
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidStatusTransitionException($from->value, $to->value);
            }

            if ($to === OrderStatus::Cancelled) {
                $this->inventory->release(
                    $order->items
                        ->sortBy('product_id')
                        ->map(fn (OrderItem $item) => new StockItem($item->product_id, $item->quantity))
                        ->values()
                        ->all(),
                );
            }

            $order->status = $to;
            $order->save();

            return [$order, $from];
        });

        // Dispatched only after the transaction has committed.
        OrderStatusChanged::dispatch($order->id, $order->user_id, $from->value, $to->value);

        return $order;
    }

    /**
     * Sums duplicate products into one line and sorts by product_id (prevents deadlocks).
     *
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     * @return array<int, int> quantity keyed by product id
     */
    private function mergeItems(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $merged[$productId] = ($merged[$productId] ?? 0) + (int) $item['quantity'];
        }

        ksort($merged);

        return $merged;
    }

    /**
     * A missing or soft-deleted product is a 422 and nothing has been reserved yet.
     *
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     * @param  array<int, \Modules\Products\Contracts\DTOs\ProductSnapshot>  $snapshots
     */
    private function assertProductsExist(array $items, array $snapshots): void
    {
        $errors = [];

        foreach ($items as $index => $item) {
            $productId = (int) $item['product_id'];

            if (! isset($snapshots[$productId])) {
                $errors["items.{$index}.product_id"] = ['The selected product is not available.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
