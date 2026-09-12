<?php

use App\Enums\OrderStatus;
use App\Exceptions\OrderCreationException;
use App\Jobs\ProcessOrder;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(OrderService::class);
});

test('creates an order with the correct total and decreases stock', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 100, 'stock' => 10]);

    $order = $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => 3],
    ]);

    expect($order->total_amount)->toEqual(300)
        ->and($order->status)->toBe(OrderStatus::PENDING)
        ->and($order->order_number)->not->toBeEmpty()
        ->and($order->items)->toHaveCount(1);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 7]);
});

test('dispatches the ProcessOrder job after creating an order', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['stock' => 5]);

    $order = $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);

    Queue::assertPushed(ProcessOrder::class, fn ($job) => $job->order->is($order));
});

test('throws and leaves stock unchanged when stock is insufficient', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['stock' => 2]);

    expect(fn () => $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]))->toThrow(OrderCreationException::class);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);
    $this->assertDatabaseCount('orders', 0);
    Queue::assertNothingPushed();
});

test('throws when the product is inactive', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->inactive()->create(['stock' => 10]);

    expect(fn () => $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->toThrow(OrderCreationException::class);

    $this->assertDatabaseCount('orders', 0);
});

test('rolls back everything when one item in the order fails', function () {
    Queue::fake();

    $user = User::factory()->create();
    $available = Product::factory()->create(['stock' => 10]);
    $outOfStock = Product::factory()->create(['stock' => 1]);

    expect(fn () => $this->service->createOrder($user, [
        ['product_id' => $available->id, 'quantity' => 2],
        ['product_id' => $outOfStock->id, 'quantity' => 5],
    ]))->toThrow(OrderCreationException::class);

    $this->assertDatabaseHas('products', ['id' => $available->id, 'stock' => 10]);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('throws when the same product is listed twice', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => 2],
        ['product_id' => $product->id, 'quantity' => 3],
    ]))->toThrow(OrderCreationException::class);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
    $this->assertDatabaseCount('orders', 0);
});

test('throws when the items list is empty', function () {
    Queue::fake();

    $user = User::factory()->create();

    expect(fn () => $this->service->createOrder($user, []))
        ->toThrow(OrderCreationException::class);

    $this->assertDatabaseCount('orders', 0);
    Queue::assertNothingPushed();
});

test('throws when quantity is zero', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => 0],
    ]))->toThrow(OrderCreationException::class);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
    $this->assertDatabaseCount('orders', 0);
});

test('throws when quantity is negative', function () {
    // A negative quantity must never reach decrement(), since decrement()
    // negates its argument and would increase stock instead of reducing it.
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['stock' => 10]);

    expect(fn () => $this->service->createOrder($user, [
        ['product_id' => $product->id, 'quantity' => -3],
    ]))->toThrow(OrderCreationException::class);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
    $this->assertDatabaseCount('orders', 0);
});
