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

it('reports the system unhealthy with a 503 when the database is unreachable', function (): void {
    // Bind a connection that throws instead of the real one, so the catch branch in
    // CheckHealth runs without ever touching Postgres.
    $this->mock(Illuminate\Database\ConnectionInterface::class, function ($mock): void {
        $mock->shouldReceive('selectOne')
            ->andThrow(new RuntimeException('SQLSTATE[08006] could not connect to server'));
    });

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('data.healthy', false)
        ->assertJsonPath('data.database.ok', false)
        ->assertJsonPath('data.database.version', null)
        ->assertJsonPath('data.database.reason', 'SQLSTATE[08006] could not connect to server');
});
