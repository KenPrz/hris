<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A tiny keyed endpoint that records how many times the body actually executed.
    Route::post('/api/v1/_test/increment', function (): array {
        $count = cache()->increment('idem_test_calls');

        return ['data' => ['calls' => $count]];
    })->middleware(['auth:sanctum', 'idempotent']);

    cache()->forget('idem_test_calls');
});

it('runs the body once and replays the stored response on a retry with the same key', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'key-abc'];

    $first = $this->postJson('/api/v1/_test/increment', [], $headers)->assertOk();
    $second = $this->postJson('/api/v1/_test/increment', [], $headers)->assertOk();

    // The body executed exactly once; the second call replayed the first response.
    expect($first->json('data.calls'))->toBe(1)
        ->and($second->json('data.calls'))->toBe(1)
        ->and(App\Models\IdempotencyKey::count())->toBe(1);
});

it('409s when the same key is reused with a different body', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/increment', ['a' => 1], ['Idempotency-Key' => 'key-xyz'])->assertOk();

    $this->postJson('/api/v1/_test/increment', ['a' => 2], ['Idempotency-Key' => 'key-xyz'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('confines a key to the user who minted it', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Sanctum::actingAs($alice);
    $this->postJson('/api/v1/_test/increment', [], ['Idempotency-Key' => 'shared'])->assertOk();

    // Bob replaying Alice's key + identical body is a different actor: a 409, not a
    // cached response from Alice's request.
    Sanctum::actingAs($bob);
    $this->postJson('/api/v1/_test/increment', [], ['Idempotency-Key' => 'shared'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('passes through unkeyed requests without storing anything', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/increment', [])->assertOk();

    expect(App\Models\IdempotencyKey::count())->toBe(0);
});
