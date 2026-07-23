<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// Sanctum::actingAs() fakes the guard and never mints a real token, so it would not exercise
// currentAccessToken()->delete() in LogoutController. Mint a real bearer token through the
// login endpoint instead, so logout's revocation is genuinely tested end to end.

it('revokes the token so it cannot be used again', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    $login = $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'secret-pw'])
        ->assertOk();

    $token = $login->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/logout')
        ->assertNoContent();

    // The sanctum guard caches the resolved user on itself for the lifetime of the
    // container, so a second real HTTP call within the same test would otherwise still
    // see the pre-logout user even though the token row is gone. Forget the resolved
    // guards so the next request re-authenticates from scratch against the (now-deleted)
    // token, the same as it would on a fresh process in production.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
