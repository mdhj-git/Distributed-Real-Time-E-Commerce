<?php

namespace Modules\Orders\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
use Throwable;

class OrderService
{
    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Stock lives in a separate service with its own storage, so a single database transaction can no
     * longer cover both. The order is therefore: reserve first (atomic on its own), then save the order,
     * and give the reservation back if saving fails.
     *
     * @param  array<int, array{product_id: int|string, quantity: int|string}>  $items
     */
    public function create(int $userId, array $items): Order
    {
        $quantities = $this->mergeItems($items);

        // A missing or soft-deleted product is a 422 before anything is reserved.
        $snapshots = $this->catalog->findForOrder(array_keys($quantities));
        $this->assertProductsExist($items, $snapshots);

        // Internal id that ties the reservation to this order. It is never shown in any response.
        $reference = Str::uuid()->toString();

        // $quantities is sorted by product_id, so every request reserves in the same order.
        $stockItems = array_map(
            fn (int $productId, int $quantity) => new StockItem($productId, $quantity),
            array_keys($quantities),
            $quantities,
        );

        // Outside any DB::transaction. There is no getStock check first: the reservation alone decides,
        // and its insufficient-stock exception (409) propagates untouched.
        $this->inventory->reserve($reference, $stockItems);

        try {
            $order = DB::transaction(function () use ($userId, $reference, $quantities, $snapshots): Order {
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
                    'reference' => $reference,
                    'user_id' => $userId,
                    'status' => OrderStatus::Pending,
                    'total' => Money::fromCents($totalCents),
                ]);

                // One INSERT for all items, not one per item (createMany runs a separate INSERT per row).
                // A bulk insert skips Eloquent, so the columns it would fill (order_id, timestamps) are set here.
                // OrderItem has no observers or model events, so nothing else is skipped.
                $now = now();
                OrderItem::query()->insert(array_map(
                    fn (array $row): array => $row + ['order_id' => $order->id, 'created_at' => $now, 'updated_at' => $now],
                    $rows,
                ));

                // The response needs the items. They are set from memory instead of being read back.
                // $rows is already sorted by product_id, the same order as the items() relation.
                return $order->setRelation('items', new Collection(array_map(
                    fn (array $row): OrderItem => new OrderItem($row + ['order_id' => $order->id]),
                    $rows,
                )));
            });
        } catch (Throwable $e) {
            $this->releaseAfterFailure($reference, $stockItems);

            throw $e;
        }

        // Dispatched only after the transaction has committed, and outside the try block:
        // once the order is saved its reservation must never be released.
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
                // Before the status changes. If release throws, the transaction rolls back and the status
                // stays as it was. The row lock above is what stops two cancellations returning stock twice.
                $this->inventory->release(
                    $order->reference,
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
     * Gives a reservation back after saving the order failed. A failure here is only logged,
     * so it can never hide the original exception.
     *
     * @param  array<int, StockItem>  $stockItems
     */
    private function releaseAfterFailure(string $reference, array $stockItems): void
    {
        try {
            $this->inventory->release($reference, $stockItems);
        } catch (Throwable $releaseError) {
            Log::error('Could not release reserved stock after the order failed to save.', [
                'reference' => $reference,
                'exception' => $releaseError,
            ]);
        }
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
