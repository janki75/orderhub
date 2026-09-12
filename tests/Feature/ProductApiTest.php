<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a guest cannot list products', function () {
    $response = $this->getJson('/api/products');

    $response->assertStatus(401);
});

test('an authenticated user can list products without seeing internal ids', function () {
    Sanctum::actingAs(User::factory()->create());
    Product::factory()->count(3)->create();

    $response = $this->getJson('/api/products');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('data.0.id');
});
