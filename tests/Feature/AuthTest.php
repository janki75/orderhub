<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can log in with correct credentials and receive a token', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertStatus(200)->assertJsonStructure(['token']);
});

test('login fails with an incorrect password', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});
