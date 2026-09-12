<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\OrderCreationException;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Create an order from a list of product SKU and quantity pairs,
     * reserving stock safely against concurrent requests for the same
     * product. Products are referenced by SKU, not the internal database
     * id, since the id is never exposed outside the application.
     *
     * @param  array<int, array{sku: string, quantity: int}>  $items
     */
    public function createOrder(User $user, array $items): Order
    {
        $order = DB::transaction(function () use ($user, $items) {
            $lineItems = [];
            $total = 0;

            foreach ($this->normalizeItems($items) as $item) {
                // Locking product rows in a consistent order (ascending sku)
                // stops two concurrent multi-item orders from deadlocking each other.
                $product = Product::where('sku', $item['sku'])->lockForUpdate()->first();

                if (! $product) {
                    throw new OrderCreationException('One of the selected products no longer exists.');
                }

                if (! $product->is_active) {
                    throw new OrderCreationException("{$product->name} is not available for purchase.");
                }

                if ($product->stock < $item['quantity']) {
                    throw new OrderCreationException("Only {$product->stock} unit(s) of {$product->name} left in stock.");
                }

                $subtotal = $product->price * $item['quantity'];
                $total += $subtotal;

                $lineItems[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'subtotal' => $subtotal,
                ];
            }

            $order = Order::create([
                'user_id' => $user->id,
                'status' => OrderStatus::PENDING,
                'total_amount' => $total,
            ]);

            foreach ($lineItems as $lineItem) {
                $order->items()->create([
                    'product_id' => $lineItem['product']->id,
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['unit_price'],
                    'subtotal' => $lineItem['subtotal'],
                ]);

                $lineItem['product']->decrement('stock', $lineItem['quantity']);
            }

            return $order;
        });

        // Dispatched after the transaction above has committed, so a worker
        // never processes an order whose stock reservation was rolled back.
        ProcessOrder::dispatch($order);

        return $order;
    }

    /**
     * Reject a request that lists the same product twice (a real cart already
     * merges duplicates before checkout, so this indicates a client bug rather
     * than a valid order) and sort by sku so product rows are always locked
     * in the same order across requests.
     *
     * @param  array<int, array{sku: string, quantity: int}>  $items
     * @return array<int, array{sku: string, quantity: int}>
     */
    private function normalizeItems(array $items): array
    {
        if (empty($items)) {
            throw new OrderCreationException('An order must contain at least one item.');
        }

        foreach ($items as $item) {
            if ((int) ($item['quantity'] ?? 0) < 1) {
                // Guarding this here, not just in the FormRequest, matters: Eloquent's
                // decrement() negates whatever it is given, so a negative quantity
                // would increase stock instead of decreasing it if it reached that far.
                throw new OrderCreationException('Item quantity must be at least 1.');
            }
        }

        $skus = array_column($items, 'sku');

        if (count($skus) !== count(array_unique($skus))) {
            throw new OrderCreationException('Each product can only appear once in an order request.');
        }

        return collect($items)->sortBy('sku')->values()->all();
    }
}
