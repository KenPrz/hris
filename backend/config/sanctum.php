<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Sanctum
|--------------------------------------------------------------------------
|
| This file exists for `expiration` alone. Without it Sanctum falls back to its
| packaged config, where `expiration` is null — meaning an issued token is valid
| forever, and a leaked one stays valid forever with it. Everything else below is
| the package default, restated so the file is a complete config rather than a
| partial that silently drops keys.
|
| `stateful` is deliberately empty: HRIS authenticates with bearer tokens only. The
| frontend is served from the same origin by Caddy in production and by Next's
| rewrite in dev, so there is no cross-origin SPA cookie flow to whitelist, and an
| empty list is narrower than the package's localhost defaults.
|
*/

return [

    'stateful' => [],

    'guard' => ['web'],

    /*
    | Minutes until an issued token expires. Twelve hours covers one working day plus
    | a margin, so an employee who signs in at the start of a shift is not asked again
    | during it, while a token lifted from a shared machine dies the same day.
    |
    | Expiry is evaluated against the token's created_at on every request, so lowering
    | this value takes effect immediately for tokens already issued — it is not baked
    | in at creation.
    */
    'expiration' => (int) env('SANCTUM_EXPIRATION_MINUTES', 720),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
