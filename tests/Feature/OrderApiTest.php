<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a guest cannot create an order', function () {
    $product = Product::factory()->create();

    $response = $this->postJson('/api/orders', [
        'items' => [['sku' => $product->sku, 'quantity' => 1]],
    ]);

    $response->assertStatus(401);
});

test('an authenticated user can create an order', function () {
    Sanctum::actingAs(User::factory()->create());
    $product = Product::factory()->create(['price' => 100, 'stock' => 10]);

    $response = $this->postJson('/api/orders', [
        'items' => [['sku' => $product->sku, 'quantity' => 2]],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('total_amount', '200.00')
        ->assertJsonPath('status', 'pending')
        ->assertJsonCount(1, 'items')
        ->assertJsonMissingPath('id');
});

test('order creation fails validation with no items', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/orders', ['items' => []]);

    $response->assertStatus(422)->assertJsonValidationErrors('items');
});

test('order creation fails validation with duplicate skus', function () {
    Sanctum::actingAs(User::factory()->create());
    $product = Product::factory()->create();

    $response = $this->postJson('/api/orders', [
        'items' => [
            ['sku' => $product->sku, 'quantity' => 1],
            ['sku' => $product->sku, 'quantity' => 2],
        ],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['items.0.sku']);
});

test('order creation fails validation with a non positive quantity', function () {
    Sanctum::actingAs(User::factory()->create());
    $product = Product::factory()->create();

    $response = $this->postJson('/api/orders', [
        'items' => [['sku' => $product->sku, 'quantity' => 0]],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
});

test('order creation fails validation with an unknown sku', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/orders', [
        'items' => [['sku' => 'DOES-NOT-EXIST', 'quantity' => 1]],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['items.0.sku']);
});

test('order creation returns a clear error when stock is insufficient', function () {
    Sanctum::actingAs(User::factory()->create());
    $product = Product::factory()->create(['stock' => 1]);

    $response = $this->postJson('/api/orders', [
        'items' => [['sku' => $product->sku, 'quantity' => 5]],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', "Only 1 unit(s) of {$product->name} left in stock.");
});

test('a user can view their own order by order number', function () {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create();

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/orders/{$order->order_number}");

    $response->assertStatus(200)->assertJsonPath('order_number', $order->order_number);
});

test('a user cannot view another users order', function () {
    $owner = User::factory()->create();
    $order = Order::factory()->for($owner)->create();

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson("/api/orders/{$order->order_number}");

    $response->assertStatus(403);
});

test('viewing a nonexistent order returns not found', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/orders/ORD-DOES-NOT-EXIST');

    $response->assertStatus(404);
});
