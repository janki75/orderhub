<?php

use App\Enums\OrderStatus;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('marks the order completed and notifies the owner by email', function () {
    Notification::fake();

    $order = Order::factory()->create(['status' => OrderStatus::PENDING]);
    $product = Product::factory()->create();

    $order->items()->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => $product->price,
        'subtotal' => $product->price * 2,
    ]);

    (new ProcessOrder($order))->handle();

    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);

    Notification::assertSentTo($order->user, OrderCompleted::class);
});
