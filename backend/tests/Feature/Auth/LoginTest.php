<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('issues a token for correct credentials', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'secret-pw'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
});

it('rejects a wrong password without revealing the account exists', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

it('gives the same answer for an unknown email as for a wrong password', function (): void {
    // No account exists. The code and status must be identical to the wrong-password case,
    // so an attacker cannot enumerate accounts.
    $this->postJson('/api/v1/login', ['email' => 'nobody@delsan.test', 'password' => 'whatever'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

it('rate-limits repeated login attempts', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong']);
    }

    $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests');
});
