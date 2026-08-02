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

// claim ordering -------------------------------------------------------------------------

it('claims the key BEFORE running the guarded work', function (): void {
    // The whole fix. This used to be `SELECT … FOR UPDATE` on the key, then the work, then
    // the insert — and a lock on a row that does not exist yet locks nothing, so two first
    // uses of one key both reached the work and the loser's insert raised a 23505 with no
    // savepoint: a 500 where a replayed 2xx was owed, on top of executing twice.
    //
    // If the claim moves back after the work, this route observes no row and fails.
    Route::post('/api/v1/_test/observe', fn (): array => ['data' => [
        'claimed' => App\Models\IdempotencyKey::whereKey('observe-key')->exists(),
    ]])->middleware(['auth:sanctum', 'idempotent']);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/observe', [], ['Idempotency-Key' => 'observe-key'])
        ->assertOk()
        ->assertJsonPath('data.claimed', true);
});

it('resolves the claim with the response once the work succeeds', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/increment', [], ['Idempotency-Key' => 'resolve-key'])->assertOk();

    $row = App\Models\IdempotencyKey::whereKey('resolve-key')->sole();

    // A committed claim always carries its outcome — never a half-written key that a later
    // replay would serve as `null`. The CHECK constraint enforces the pairing too.
    expect($row->response_code)->toBe(200)
        ->and($row->response_body)->not->toBeNull();
});

it('releases the key when the guarded work fails, so the client can retry', function (): void {
    // Only success keeps the key. The claim now exists up front, so "stays retryable" means
    // releasing it rather than simply never writing it.
    Route::post('/api/v1/_test/failing', function () {
        cache()->increment('idem_fail_calls');

        return response()->json(['error' => ['code' => 'nope', 'message' => 'no']], 422);
    })->middleware(['auth:sanctum', 'idempotent']);

    cache()->forget('idem_fail_calls');
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/failing', [], ['Idempotency-Key' => 'fail-key'])->assertStatus(422);

    expect(App\Models\IdempotencyKey::whereKey('fail-key')->exists())->toBeFalse();

    // Genuinely retryable: the same key runs the work again rather than replaying a failure.
    $this->postJson('/api/v1/_test/failing', [], ['Idempotency-Key' => 'fail-key'])->assertStatus(422);

    expect(cache()->get('idem_fail_calls'))->toBe(2);
});
