<?php

declare(strict_types=1);

it('reports the system healthy against a real database', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.healthy', true)
        ->assertJsonPath('data.app_version', 'test')
        ->assertJsonPath('data.database.ok', true)
        ->assertJsonPath('data.database.reason', null);
});

it('names the Postgres version so an operator can see what they are running', function (): void {
    $response = $this->getJson('/api/v1/health')->assertOk();

    expect($response->json('data.database.version'))->toStartWith('PostgreSQL');
});

it('wraps success in the data envelope and nothing else', function (): void {
    // Success is always {"data": ...}; errors are always {"error": ...}. Never both,
    // never a bare array. See docs/03-api.md.
    $body = $this->getJson('/api/v1/health')->assertOk()->json();

    expect(array_keys($body))->toBe(['data']);
});
