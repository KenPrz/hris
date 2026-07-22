<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TeapotRefused;

beforeEach(function (): void {
    Route::get('/api/v1/_test/domain-failure', function (): never {
        throw new TeapotRefused('teapot');
    });

    Route::post('/api/v1/_test/validated', function (): never {
        request()->validate(['name' => ['required', 'string']]);
        throw new RuntimeException('unreachable');
    });
});

it('renders a domain exception into the error envelope', function (): void {
    $this->getJson('/api/v1/_test/domain-failure')
        ->assertStatus(418)
        ->assertExactJson([
            'error' => [
                'code' => 'teapot_refused',
                'message' => 'That vessel cannot brew coffee.',
                'details' => ['vessel' => 'teapot'],
            ],
        ]);
});

it('renders a framework 404 in the same envelope', function (): void {
    // The half that gets forgotten. If only DomainException is handled, Laravel's own
    // shape leaks through and "one shape, everywhere, one client code path" is a claim
    // the API does not honour.
    $this->getJson('/api/v1/no-such-route')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('renders a method mismatch in the same envelope', function (): void {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('renders validation failures as 400, not 422', function (): void {
    // 422 is reserved for requests that are structurally fine but semantically
    // rejected. A malformed body is a 400. See docs/03-api.md.
    $this->postJson('/api/v1/_test/validated', [])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.fields.name.0', 'The name field is required.');
});

it('serializes empty details as an object, never an array', function (): void {
    // A client typing details as Record<string, unknown> must never be handed [].
    $raw = $this->getJson('/api/v1/no-such-route')->assertStatus(404)->getContent();

    expect($raw)->toContain('"details":{}');
});
