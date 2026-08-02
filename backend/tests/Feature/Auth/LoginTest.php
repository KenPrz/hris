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

it('returns a byte-identical body for an unknown email and a wrong password', function (): void {
    // The response body (status + JSON) must be indistinguishable so the endpoint cannot be
    // used to enumerate accounts. The || in the controller must not short-circuit Hash::check
    // for a missing user either, or the two paths would differ in timing even with an
    // identical body — see LoginController.
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    $wrongPassword = $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong']);
    $unknownEmail = $this->postJson('/api/v1/login', ['email' => 'nobody@delsan.test', 'password' => 'whatever']);

    expect($unknownEmail->getStatusCode())->toBe($wrongPassword->getStatusCode());
    expect($unknownEmail->getContent())->toBe($wrongPassword->getContent());
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

// token lifetime ------------------------------------------------------------------------

it('mints a token that actually expires', function (): void {
    // config/sanctum.php did not exist, so Sanctum fell back to its packaged default of
    // `expiration => null`: an issued token was valid forever, and a leaked one with it.
    $user = User::factory()->create([
        'email' => 'maria@delsan.test',
        'password' => Hash::make('secret-pw'),
    ]);

    $token = $this->postJson('/api/v1/login', [
        'email' => 'maria@delsan.test',
        'password' => 'secret-pw',
    ])->assertOk()->json('data.token');

    $minutes = (int) config('sanctum.expiration');
    expect($minutes)->toBeGreaterThan(0);

    // Still good inside the window.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertOk();

    // Dead past it. Expiry is evaluated against created_at on every request, so this is a
    // property of the config rather than something baked into the token at mint time.
    $this->travel($minutes + 1)->minutes();

    // Sanctum's RequestGuard memoizes the user it resolved for the request above, and the
    // application instance persists across requests within one test — so without this the
    // second call reuses the cached user and never re-checks the token at all. Dropping the
    // guards is what makes this assert the expiry rather than the cache.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertStatus(401);

    $this->travelBack();
});

it('mints a token with an explicit ability rather than the wildcard', function (): void {
    // createToken() with no abilities expands to ['*'] — a token that can do anything the
    // API ever grows. One named grant today means a future device or integration token is
    // a different grant, not a silent superset of this one.
    $user = User::factory()->create([
        'email' => 'maria@delsan.test',
        'password' => Hash::make('secret-pw'),
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'maria@delsan.test',
        'password' => 'secret-pw',
    ])->assertOk();

    $token = $user->tokens()->sole();

    expect($token->abilities)->toBe(['session'])
        ->and($token->abilities)->not->toContain('*');
});
