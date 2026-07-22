<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Auth\AuthenticationException;
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

    Route::get('/api/v1/_test/unauthenticated', function (): never {
        throw new AuthenticationException;
    });

    Route::get('/api/v1/_test/deny-as-not-found', function (): never {
        AccessResponse::denyAsNotFound()->authorize();
    });

    Route::get('/api/v1/_test/conflict', function (): never {
        abort(409, 'Conflict');
    });

    Route::get('/api/v1/_test/boom', function (): never {
        throw new RuntimeException('boom');
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

it('renders a missing authentication in the same envelope', function (): void {
    $this->getJson('/api/v1/_test/unauthenticated')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('renders a statused denial in the same envelope', function (): void {
    // The 404-not-403 rule in docs/00-overview.md is written as denyAsNotFound(), which
    // is denyWithStatus(404). Laravel's prepareException() rewrites a *statused*
    // AuthorizationException into a plain HttpException — not AccessDeniedHttpException,
    // not NotFoundHttpException — so nothing but a closed envelope catches it. The most
    // privacy-sensitive refusal in the system must not be the one that escapes.
    $this->getJson('/api/v1/_test/deny-as-not-found')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('renders an arbitrary abort() in the same envelope', function (): void {
    // Nothing enumerates 409. A closed envelope catches it anyway; an enumerated one
    // would not, and would not have told anyone.
    $this->getJson('/api/v1/_test/conflict')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
});

it('renders an uncaught throwable as a 500 in the same envelope', function (): void {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error');
});
